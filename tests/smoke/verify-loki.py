"""Assert actual Alloy ingestion, timestamp parsing and JSON queries in local Loki."""
import datetime
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

endpoint, log_path = sys.argv[1:]
with open(log_path) as log_file:
    expected = [json.loads(line) for line in log_file]
assert len(expected) == 5
query = '{job="laravel-logging-smoke"} | json | __error__=""'
start = int((datetime.datetime.fromisoformat(expected[0]["timestamp"]).timestamp() - 60) * 1e9)
deadline = time.monotonic() + 90
last_error = None
while time.monotonic() < deadline:
    params = urllib.parse.urlencode({"query": query, "start": start, "end": time.time_ns(), "limit": 100})
    try:
        with urllib.request.urlopen(endpoint + "/loki/api/v1/query_range?" + params, timeout=5) as response:
            data = json.load(response)
        streams = data["data"]["result"]
        values = [(timestamp, line) for stream in streams for timestamp, line, *_ in stream["values"]]
        if len(values) == len(expected):
            break
    except (urllib.error.URLError, TimeoutError, ConnectionError, KeyError) as error:
        last_error = str(error)
    time.sleep(2)
else:
    raise SystemExit(f"Loki did not ingest all smoke records: {last_error}")

actual = [json.loads(line) for _, line in values]
assert sorted(actual, key=lambda row: row["timestamp"]) == sorted(expected, key=lambda row: row["timestamp"])
assert "SYNTHETIC_SECRET" not in json.dumps(actual)
for timestamp, line in values:
    entry_time = datetime.datetime.fromisoformat(json.loads(line)["timestamp"])
    # Avoid floating-point nanosecond rounding when checking the collector timestamp.
    seconds = int(entry_time.timestamp())
    assert int(timestamp) == seconds * 1_000_000_000 + entry_time.microsecond * 1000

# Query series separately: the | json query also extracts body fields into result labels.
params = urllib.parse.urlencode({"match[]": '{job="laravel-logging-smoke"}', "start": start, "end": time.time_ns()})
with urllib.request.urlopen(endpoint + "/loki/api/v1/series?" + params, timeout=5) as response:
    series = json.load(response)["data"]
assert series
for labels in series:
    assert set(labels) <= {"job", "service", "service_name", "environment", "component", "level"}, labels
    assert labels["service"] == "smoke-app"
print("Loki/Alloy smoke test passed: 5 records, JSON queries, original timestamps, safe labels and redaction.")
