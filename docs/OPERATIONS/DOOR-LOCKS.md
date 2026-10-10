# Door locks (key cards and codes that follow the stay)

Traceability: PRD gap review of 2026-10-10 (key cards at check-in and check-out are expected by hotels that have electronic locks). Settings and jobs: `/front-office/door-locks` (right `front-office.door-lock.manage`).

## What is built

InnSYnc does not talk to a lock system itself. A hotel's lock system sits on the hotel's own network, and InnSYnc may be on shared hosting that cannot reach into it. So the line is drawn the other way round:

1. **Stay events make jobs.** With door locks switched on, `DoorLockJobConsumer` turns the stay events into key jobs, once per event: check-in → *issue* keys for the room until the departure day; check-out → *revoke*; room move → *revoke* the old room and *issue* for the new one; extension → *update* the last day. Nothing is made while door locks are off.
2. **A small program beside the lock system fetches the jobs** with the token of the property (`GET /api/bridge/door-lock/jobs`) and **reports** each one (`POST /api/bridge/door-lock/jobs/{id}/result` with `done` or `failed`). `tools/door-lock-bridge-example.mjs` is a starting point: the only part left to write is the call to the lock vendor's own interface. Its `--dry-run` reports every job as done without touching a lock, which proves the line before the vendor is involved.
3. **People stay in control.** The page lists every job and its status (waiting, fetched, done, failed, settled by hand). A failed job, or one fetched and never answered, can be tried again; a job can be settled by hand (the key was made at the lock station) with a reason. Everything is audited.

## The contract (plain JSON, the same for every make of lock)

| | |
| --- | --- |
| Credential | `Authorization: Bearer <property ulid>.<48 hex>`; shown once when made, only its hash is kept; making a new one stops the old; refused (401) while door locks are off |
| Fetch | `GET /api/bridge/door-lock/jobs?limit=20` → `{"jobs": [{"id", "kind": "issue\|revoke\|update", "room", "reservation", "keys", "valid_until"}]}`. A fetched job that gets no report comes again after 5 minutes |
| Report | `POST /api/bridge/door-lock/jobs/{id}/result` `{"outcome": "done\|failed", "message": "…≤200"}`. Sending the same report again changes nothing; a report for a job settled by hand changes nothing |
| Privacy | No guest name, phone, ID or e-mail is ever in a job: the room, the reservation number, the number of keys and the last day only |
| Rate | 120 calls a minute per address |

## Makes of lock

The page names the makes this market meets most (ASSA ABLOY VingCard / Visionline, Onity, SALTO, dormakaba / Saflok, Häfele / Dialock, other). **The name is a label for the people who set it up; InnSYnc speaks one format to all.** No vendor-specific interface is built or claimed: each make has its own software (some on a server in the hotel, some in the cloud) and its own licence for the interface, and that documentation is needed to write the `handle()` of the bridge. Nothing here has been tried against a real lock. When the hotel names its make, the bridge's `handle()` is written and tested against the vendor's sandbox or a lock on a desk, then a pilot with one floor.

## Setting up

1. In `/front-office/door-locks`, **Make the token**, copy it into the bridge program's configuration (it is shown once).
2. Run the bridge with `--dry-run` on the hotel network; check in a test reservation and see the job go from *waiting* to *done*.
3. Connect `handle()` to the lock system; switch door locks on for real.

Open points for the hotel: how many keys per stay (1 to 6, default 2); the hour the keys stop on the last day is the lock system's own check-out time; keys for a guest who is checked in without a room move are made at check-in only.
