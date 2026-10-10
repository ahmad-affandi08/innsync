// A starting point for the program that sits beside a hotel's door lock system (docs/OPERATIONS/DOOR-LOCKS.md). It fetches the key jobs from InnSYnc with the token of the property, hands each one to
// `handle()` (the part that talks to YOUR lock system: replace it with the call your lock vendor documents) and reports whether it worked.
//
//   node tools/door-lock-bridge-example.mjs --url=https://hotel.example --token=<property ulid>.<secret> [--every=5] [--dry-run]
//
// With --dry-run nothing is sent to a lock: every job is reported as done, which is how to prove the line between InnSYnc and this program before the lock vendor is involved.
const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)))
const base = String(args.url ?? '').replace(/\/$/, '')
const token = String(args.token ?? '')
const every = Math.max(2, Number(args.every ?? 5)) * 1000
const dryRun = 'dry-run' in args

if (base === '' || token === '') {
    console.error('Usage: node door-lock-bridge-example.mjs --url=https://hotel.example --token=<token> [--every=5] [--dry-run]')
    process.exit(2)
}

/**
 * One job: { id, kind: 'issue' | 'revoke' | 'update', room, reservation, keys, valid_until }.
 *  issue  - make `keys` keys for `room`, working until the end of `valid_until` (the hotel's check-out time that day);
 *  revoke - make the keys of `room` stop working (check-out, or the room the guest moved out of);
 *  update - give the keys of `room` the new last day `valid_until`.
 * Return { ok: true } or { ok: false, message } (at most 200 characters, no guest data).
 */
async function handle(job) {
    if (dryRun) {
        return { ok: true }
    }

    // Call the lock system here. Nothing about the guest is in the job; the lock vendor's own interface decides how a key is encoded.
    return { ok: false, message: 'handle() is not connected to a lock system yet' }
}

async function call(path, init = {}) {
    const response = await fetch(`${base}/api/bridge/door-lock${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' } })

    if (response.status === 401) {
        throw new Error('The token is wrong, was replaced, or door locks are switched off in InnSYnc.')
    }

    if (!response.ok) {
        throw new Error(`InnSYnc answered ${response.status} to ${path}`)
    }

    return response.json()
}

async function once() {
    const { jobs } = await call('/jobs?limit=20')

    for (const job of jobs) {
        let result

        try {
            result = await handle(job)
        } catch (error) {
            result = { ok: false, message: String(error.message ?? error).slice(0, 200) }
        }

        await call(`/jobs/${job.id}/result`, { method: 'POST', body: JSON.stringify({ outcome: result.ok ? 'done' : 'failed', message: result.message ?? null }) })
        console.log(`${new Date().toISOString()} ${job.kind} room ${job.room} -> ${result.ok ? 'done' : `failed (${result.message})`}`)
    }
}

for (;;) {
    try {
        await once()
    } catch (error) {
        console.error(`${new Date().toISOString()} ${error.message}`)
    }

    await new Promise((resolve) => setTimeout(resolve, every))
}
