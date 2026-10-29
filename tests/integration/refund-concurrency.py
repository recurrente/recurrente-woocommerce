"""Use: python3 tests/integration/refund-concurrency.py -- wp --path=/fixture-store.

The WordPress command may be a sandboxed wrapper. It must point to the same dedicated
fixture store used by refund-operations.php. Run once in CPT and once in HPOS.
"""
import json
import os
from pathlib import Path
import select
import subprocess
import sys
import time

command = sys.argv[sys.argv.index("--") + 1:]
script = str(Path(__file__).with_suffix(".php").resolve())


def run(actor, *args):
    result = subprocess.run(command + ["eval-file", script, actor, *map(str, args)],
                            text=True, capture_output=True, check=True, timeout=45)
    return json.loads(result.stdout.splitlines()[-1])


def read_lines(process, count, timeout=45):
    deadline = time.monotonic() + timeout
    output = b""
    while output.count(b"\n") < count:
        remaining = deadline - time.monotonic()
        if remaining <= 0 or not select.select([process.stdout], [], [], remaining)[0]:
            raise TimeoutError("Native refund did not reach the expected test barrier.")
        chunk = os.read(process.stdout.fileno(), 4096)
        if not chunk:
            raise RuntimeError("Native refund ended before the expected test barrier.")
        output += chunk
    lines = output.decode().splitlines()
    assert len(lines) == count, lines
    return lines


order = run("setup")["order_id"]
native = subprocess.Popen(command + ["eval-file", script, "native", str(order)],
                          text=True, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE)
try:
    marker, = read_lines(native, 1)
    assert marker == "NATIVE_IN_HTTP", marker
    overlap = run("overlap", order)
    assert overlap == {"unconfirmed": True, "http_calls": 0}, overlap
    callback_before = run("callback", order)
    assert callback_before["status"] == 503, callback_before
    native.stdin.write("continue\n")
    native.stdin.flush()
    refund_result, marker = read_lines(native, 2)
    result = json.loads(refund_result)
    assert result == {"unconfirmed": True, "http_calls": 1}, result
    assert marker == "NATIVE_AFTER_REFUND", marker
    query = run("query", order)
    assert query == {
        "warning": True,
        "note": "No se pudo consultar este reembolso. Esperá unos segundos y volvé a intentarlo.",
        "new_notes": 1,
        "http_calls": 0,
        "journal_unchanged": True,
        "refunded": 0,
    }, query
    callback_before_shutdown = run("callback", order)
    assert callback_before_shutdown["status"] == 503, callback_before_shutdown
    output, error = native.communicate("finish\n", timeout=45)
    assert native.returncode == 0, error
    callback_after = run("callback", order)
    assert callback_after == {"status": 200, "refunded": 25, "refunds": 1}, callback_after
    replay = run("callback", order)
    assert replay == callback_after, replay
    print(json.dumps({"order_id": order, "native": result, "overlap": overlap,
                      "callback_before": callback_before, "query": query,
                      "callback_before_shutdown": callback_before_shutdown, "callback_after": callback_after,
                      "replay": replay}))
finally:
    if native.poll() is None:
        native.terminate()
        native.communicate(timeout=10)
