"""Exercise two isolated containers with one persistent Octane worker each."""
import concurrent.futures
import json
import subprocess
import sys
import time
import urllib.error
import urllib.request

containers = sys.argv[1:]
expected = {name: {} for name in containers}
worker_ids = {}
hostnames = {}
urls = {}


def fetch(url, request_id=None):
    request = urllib.request.Request(url, headers={} if request_id is None else {"X-Request-Id": request_id})
    try:
        response = urllib.request.urlopen(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers.get("X-Request-Id"), response.read()


def probe(name, step, request_id=None, same_worker=True):
    status, actual_id, body = fetch(urls[name] + "/probe/" + step, request_id)
    assert status == (500 if step == "failure" else 200), (name, step, status, body[:300])
    assert actual_id and (request_id is None or actual_id == request_id), (name, step, actual_id)
    if status == 200:
        data = json.loads(body)
        if same_worker and name in worker_ids:
            assert data["worker_id"] == worker_ids[name], "Worker restarted: persistence not exercised"
        worker_ids[name] = data["worker_id"]
        hostnames[name] = data["hostname"]
    expected[name][actual_id] = step
    return actual_id


for name in containers:
    address = subprocess.check_output(["docker", "port", name, "8000/tcp"], text=True).strip()
    urls[name] = "http://" + address
    deadline = time.monotonic() + 60
    while True:
        try:
            if fetch(urls[name] + "/ready")[0] == 200:
                break
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            pass
        if time.monotonic() > deadline:
            raise AssertionError("Octane did not become ready: " + name)
        time.sleep(0.25)
    for step in ("first", "failure", "jobs", "after"):
        probe(name, step, name + "-" + step)
    probe(name, "generated")

assert len(set(hostnames.values())) == len(containers), "Replicas must have independent identities"
assert len(set(worker_ids.values())) == len(containers), "Replicas must boot independently"
with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
    list(pool.map(lambda item: probe(item[0], item[1], item[0] + "-" + item[1]), [
        (name, "parallel-" + str(i)) for i in range(20) for name in containers
    ]))

# Reload one worker and prove fresh boot/context without changing the other replica.
previous_worker = worker_ids[containers[0]]
subprocess.run(["docker", "exec", containers[0], "php", "artisan", "octane:reload", "--server=frankenphp"], check=True, capture_output=True)
probe(containers[0], "reloaded", same_worker=False)
assert worker_ids[containers[0]] != previous_worker, "Worker did not reload"
probe(containers[1], "unaffected")

# Stop gracefully to also flush the server's output forwarding before inspection.
subprocess.run(["docker", "stop", "--time=10", *containers], check=True, capture_output=True)
for name in containers:
    raw = subprocess.check_output(["docker", "logs", name], stderr=subprocess.STDOUT, text=True)
    assert "SYNTHETIC_SECRET" not in raw, "Unredacted secret in container output"
    entries = []
    for line in raw.splitlines():
        try:
            entry = json.loads(line)
        except ValueError:
            continue  # Artisan startup messages are not application log records.
        if entry.get("schema_version") == 1:
            entries.append(entry)
    assert len(entries) == 2 * len(expected[name]) + 3, (name, len(entries), len(expected[name]), raw[-2000:])
    assert {entry["request_id"] for entry in entries} == set(expected[name])
    assert all(entry["hostname"] == hostnames[name] for entry in entries)
    assert all(entry["service"] == "smoke-app" for entry in entries)
    for request_id, step in expected[name].items():
        group = [entry for entry in entries if entry["request_id"] == request_id]
        assert group[0]["message"] == "start"
        assert group[0]["context"]["password"] == "[REDACTED]"
        assert all(entry["context"].get("tenant") == ("first-only" if step == "first" else None) for entry in group)
        assert all(entry["route"] == "probe/{step}" for entry in group)
        if step == "jobs":
            assert [entry["message"] for entry in group] == ["start", "outer", "inner", "outer resumed", "end"]
            assert group[1]["job_id"] == group[3]["job_id"] != group[2]["job_id"]
            assert "job_id" not in group[0] and "job_id" not in group[-1]
        else:
            assert len(group) == 2
            assert all("job_id" not in entry for entry in group)
print("Octane smoke passed: 2 replicas, stderr JSON, 52 requests, nested jobs, failures, concurrent clients and worker reload.")
