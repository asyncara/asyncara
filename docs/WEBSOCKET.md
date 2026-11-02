# WebSocket status

**NOT_IMPLEMENTED**. There is no WebSocket API, handshake, frame parser, server
upgrade path or interoperability claim in this checkpoint. TCP/TLS and HTTP
support do not by themselves supply WebSocket semantics.

Implementation remains after HTTP stream ownership/backpressure work. It must
cover the [WebSocket protocol](https://www.rfc-editor.org/rfc/rfc6455): verified
client handshake, role-specific masking, bounded length parsing, fragmentation,
continuations, text UTF-8 validation, control-frame limits, ping/pong and close
state transitions. TLS verification and cancellation must use tested networking
primitives. Slow-consumer policy and resource cleanup must be explicit.

Required evidence before advertising support: local client/server interoperation,
malformed/truncated/oversized frame corpus, independent implementation tests,
ASan/UBSan fuzzing where native parsing is added, and shutdown/cancellation tests.
No empty parser or TCP string wrapper has been added to imply completion.
