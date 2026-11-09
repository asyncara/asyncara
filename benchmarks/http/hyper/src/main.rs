//! HTTP/1.1 engine ceiling experiment, NOT an Asyncara backend or PHP benchmark.
//! One Tokio interpreter thread, persistent connections, bounded checked bodies.
use http_body_util::{BodyExt, Empty};
use hyper::{body::Bytes, Request, Uri};
use hyper_util::rt::TokioIo;
use std::{error::Error, net::SocketAddr, sync::Arc, time::{Duration, Instant}};
use tokio::{net::TcpStream, sync::Barrier};

type Failure = Box<dyn Error + Send + Sync>;
fn usage() -> (f64, i64) {
    let mut r = std::mem::MaybeUninit::<libc::rusage>::uninit();
    // Successful getrusage initializes all fields; no foreign memory escapes.
    let r = unsafe {
        assert_eq!(libc::getrusage(libc::RUSAGE_SELF, r.as_mut_ptr()), 0);
        r.assume_init()
    };
    (r.ru_utime.tv_sec as f64 + r.ru_utime.tv_usec as f64 / 1e6 +
     r.ru_stime.tv_sec as f64 + r.ru_stime.tv_usec as f64 / 1e6, r.ru_maxrss)
}
async fn run(url: Uri, count: usize, concurrency: usize, size: usize) -> Result<(), Failure> {
    if url.scheme_str() != Some("http") || concurrency == 0 || concurrency > 256 || count == 0 ||
        count % concurrency != 0 || size > 16 * 1024 * 1024 { return Err("Invalid benchmark parameters".into()); }
    // Numeric loopback only: no DNS worker hides in this single-thread experiment.
    let addr: SocketAddr = url.authority().ok_or("Missing authority")?.as_str().parse()?;
    if !addr.ip().is_loopback() { return Err("Local isolated fixture required".into()); }
    let ready = Arc::new(Barrier::new(concurrency + 1));
    let go = Arc::new(Barrier::new(concurrency + 1));
    let mut tasks = Vec::new();
    for _ in 0..concurrency {
        let ready = ready.clone(); let go = go.clone(); let url = url.clone();
        tasks.push(tokio::spawn(async move {
            let stream = TcpStream::connect(addr).await?; stream.set_nodelay(true)?;
            let (mut sender, connection) = hyper::client::conn::http1::Builder::new()
                .max_buf_size(65536).handshake(TokioIo::new(stream)).await?;
            let driver = tokio::spawn(connection);
            let expected = vec![b'x'; size]; let mut times = Vec::with_capacity(count / concurrency);
            for phase in 0..2 {
                if phase == 1 { ready.wait().await; go.wait().await; }
                for _ in 0..if phase == 0 { 10 } else { count / concurrency } {
                    let began = Instant::now();
                    let request = Request::builder().uri(url.path_and_query().ok_or("Missing path")?.as_str())
                        .header("Host", url.authority().unwrap().as_str()).body(Empty::<Bytes>::new())?;
                    let response = sender.send_request(request).await?;
                    if response.status() != 200 { return Err::<_, Failure>("Status mismatch".into()); }
                    let mut body = response.into_body(); let mut received = Vec::with_capacity(size);
                    while let Some(frame) = body.frame().await {
                        if let Some(data) = frame?.data_ref() {
                            if data.len() > size - received.len() { return Err("Oversized response".into()); }
                            received.extend_from_slice(data);
                        }
                    }
                    if received != expected { return Err("Response mismatch".into()); }
                    if phase == 1 { times.push(began.elapsed().as_secs_f64() * 1000.0); }
                }
            }
            Ok::<_, Failure>((times, sender, driver))
        }));
    }
    ready.wait().await;
    let began = Instant::now(); let cpu = usage().0; go.wait().await;
    let mut results = Vec::new();
    for task in tasks { results.push(task.await??); }
    let wall = began.elapsed().as_secs_f64(); let (after_cpu, rss) = usage();
    let mut latencies = Vec::with_capacity(count);
    for (times, sender, driver) in results { latencies.extend(times); drop(sender); driver.await??; }
    assert_eq!(latencies.len(), count); latencies.sort_by(f64::total_cmp);
    let percentile = |p: f64| latencies[((p * count as f64).ceil() as usize - 1).min(count - 1)];
    println!("{}", serde_json::json!({"mode":"hyper_standalone","php":null,
        "scope":"Rust-only HTTP/1.1 engine experiment; excludes PHP/FFI/Fiber lifecycle",
        "operations":count,"concurrency":concurrency,"bytesPerBody":size,"warmupRequests":10*concurrency,
        "wallSeconds":wall,"cpuSeconds":after_cpu-cpu,"requestsPerSecond":count as f64/wall,
        "medianMs":percentile(0.5),"p95Ms":percentile(0.95),"p99Ms":percentile(0.99),"rssPeakKiB":rss}));
    Ok(())
}
fn main() -> Result<(), Failure> {
    let args: Vec<_> = std::env::args().collect();
    if args.len() != 5 { return Err("Usage: binary URL count concurrency bodySize".into()); }
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build()?;
    runtime.block_on(async {
        tokio::time::timeout(Duration::from_secs(90),
            run(args[1].parse()?, args[2].parse()?, args[3].parse()?, args[4].parse()?)).await??;
        Ok::<_, Failure>(())
    })?;
    Ok(())
}
