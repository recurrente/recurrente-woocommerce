"""Use: python3 tests/integration/refund-concurrency.py -- wp --path=/fixture-store.

The WordPress command may be a sandboxed wrapper. It must point to the same dedicated
fixture store used by refund-operations.php. Run once in CPT and once in HPOS.
"""
import json
from pathlib import Path
import subprocess
import sys

command = sys.argv[sys.argv.index("--") + 1:]
script = str(Path(__file__).with_suffix(".php").resolve())


def run(actor, *args):
    result = subprocess.run(command + ["eval-file", script, actor, *map(str, args)],
                            text=True, capture_output=True, check=True, timeout=45)
    return json.loads(result.stdout.splitlines()[-1])


order = run("setup")["order_id"]
native = subprocess.Popen(command + ["eval-file", script, "native", str(order)],
                          text=True, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE)
try:
    marker = native.stdout.readline().strip()
    assert marker == "NATIVE_IN_HTTP", (marker, native.stderr.read())
    overlap = run("overlap", order)
    assert overlap == {"unconfirmed": True, "http_calls": 0}, overlap
    callback_before = run("callback", order)
    assert callback_before["status"] == 503, callback_before
    output, error = native.communicate("continue\n", timeout=45)
    assert native.returncode == 0, error
    result = json.loads(output.splitlines()[-1])
    assert result == {"unconfirmed": True, "http_calls": 1}, result
    callback_after = run("callback", order)
    assert callback_after == {"status": 200, "refunded": 25, "refunds": 1}, callback_after
    replay = run("callback", order)
    assert replay == callback_after, replay
    print(json.dumps({"order_id": order, "native": result, "overlap": overlap,
                      "callback_before": callback_before, "callback_after": callback_after,
                      "replay": replay}))
finally:
    if native.poll() is None:
        native.terminate()
        native.communicate(timeout=10)
