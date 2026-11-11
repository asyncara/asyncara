<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\{File,Files};
use Asyncara\Exception\{CancelledException,FilesystemException};
function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$directory = sys_get_temp_dir().'/asyncara-atomic-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    Async::run(function () use ($directory): void {
        $path = $directory.'/target';
        // Prove that the cleanup oracle observes a real unpublished native file.
        $stage = File::stage($path)->await();
        verify(count(glob($directory.'/.asyncara*')) === 1, 'Stage oracle missed its live file');
        $stage->close()->await();
        verify(glob($directory.'/.asyncara*') === [], 'Stage survived explicit close');
        try { Files::read($path)->await(); throw new RuntimeException('Missing read succeeded'); }
        catch (FilesystemException $e) { verify($e->isMissing() && $e->errno === 2 && !$e->committed, 'Missing errno lost'); }
        verify(!Files::exists($path)->await(), 'exists should recognize missing errno');
        verify(Files::replaceAtomic($path, '', durable:true)->await() === 0, 'Empty atomic write');
        verify(Files::replaceAtomic($path, 'binary'."\0".'value', durable:true, permissions:0640)->await() === 12, 'Byte count');
        verify(Files::read($path)->await() === 'binary'."\0".'value', 'Binary contents');
        verify((Files::stat($path)->await()['mode'] & 0777) === 0640, 'Permissions');
        file_put_contents($directory.'/protected', 'original');
        symlink($directory.'/protected', $directory.'/link');
        Files::replaceAtomic($directory.'/link', 'replacement')->await();
        verify(!is_link($directory.'/link') && file_get_contents($directory.'/protected') === 'original', 'Symlink target modified');
        $a = str_repeat('a', 65536); $b = str_repeat('b', 65536);
        Files::replaceAtomic($path, $a)->await();
        $tasks = [];
        for ($worker = 0; $worker < 3; $worker++) $tasks[] = Async::spawn(function () use ($path,$a,$b,$worker): void {
            for ($i = 0; $i < 40; $i++) Files::replaceAtomic($path, ($i+$worker)%2 ? $a : $b)->await();
        });
        $tasks[] = Async::spawn(function () use ($path,$a,$b): void {
            for ($i = 0; $i < 200; $i++) { $data = Files::read($path)->await(); verify($data === $a || $data === $b, 'Observed partial publication'); }
        });
        Async::gather($tasks);
        $pending = [];
        for ($i = 0; $i < 100; $i++) { $future = Files::replaceAtomic($path, $a, durable:true); $future->cancel(); $pending[] = $future; }
        foreach ($pending as $future) { try { $future->await(); } catch (CancelledException) {} }
        verify(glob($directory.'/.asyncara*') === [], 'Temporary file leak after cancellation');
        try { Files::replaceAtomic($directory.'/missing/file', 'x')->await(); throw new RuntimeException('Missing directory accepted'); }
        catch (FilesystemException $e) { verify($e->isMissing(), 'Directory errno'); }
        mkdir($directory.'/locked', 0500);
        try { Files::replaceAtomic($directory.'/locked/file', 'x')->await(); throw new RuntimeException('Write without directory permission succeeded; run this test as an ordinary user'); }
        catch (FilesystemException $e) { verify($e->errno === 13, 'Permission errno'); }
        finally { chmod($directory.'/locked', 0700); rmdir($directory.'/locked'); }
        verify(Runtime::information()->nativeMemoryBytes === 0, 'Native allocations escaped completion');
    });
    echo "Atomic visibility, durability calls, errno, races, permissions and cancellation: PASS\n";
} finally {
    foreach (glob($directory.'/*') as $path) { if (is_file($path) || is_link($path)) unlink($path); }
    foreach (glob($directory.'/.asyncara*') as $path) unlink($path);
    rmdir($directory);
}
