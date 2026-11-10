// Matched reference: original handler semantics, immutable warmed payload cache.
// No concurrent map writes. Both servers generate JSON for every response.
package main
import (
    "fmt"
    "log"
    "net"
    "os"
    "os/signal"
    "runtime"
    "strconv"
    "strings"
    "syscall"
    "time"
    "github.com/valyala/fasthttp"
)
var payloadCache = map[int][]byte{10:[]byte(strings.Repeat("x",10)),1024:[]byte(strings.Repeat("x",1024)),65536:[]byte(strings.Repeat("x",65536))}
func fastHandler(ctx *fasthttp.RequestCtx) {
    if string(ctx.Path())!="/status" && string(ctx.Path())!="/work" {ctx.Error("Not Found",404);return}
    delay,_:=strconv.Atoi(string(ctx.QueryArgs().Peek("delay_ms")))
    if delay>0 {time.Sleep(time.Duration(delay)*time.Millisecond)}
    size:=10
    if v:=ctx.QueryArgs().Peek("payload_bytes");len(v)>0 {size,_=strconv.Atoi(string(v))}
    payload,ok:=payloadCache[size];if !ok {ctx.Error("Bad Request",400);return}
    id,_:=strconv.Atoi(string(ctx.QueryArgs().Peek("id")))
    ctx.SetContentType("application/json; charset=utf-8");ctx.SetStatusCode(200)
    fmt.Fprintf(ctx,`{"id":%d,"payload":"%s"}`,id,payload)
}
func main() {
    if os.Getenv("GOMAXPROCS")=="" {runtime.GOMAXPROCS(runtime.NumCPU())}
    port:="8080";if len(os.Args)>1 {port=os.Args[1]}
    server:=&fasthttp.Server{Handler:fastHandler,Name:"Go-FastHTTP",Concurrency:250000,ReadBufferSize:4096,WriteBufferSize:4096,MaxConnsPerIP:50000,MaxRequestsPerConn:10000}
    listener,err:=net.Listen("tcp","127.0.0.1:"+port);if err!=nil {log.Fatal(err)}
    ch:=make(chan os.Signal,1);signal.Notify(ch,syscall.SIGTERM,syscall.SIGINT)
    go func(){<-ch;server.Shutdown()}()
    fmt.Println("READY",listener.Addr())
    if err:=server.Serve(listener);err!=nil {log.Fatal(err)}
}
