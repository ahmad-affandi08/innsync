# Attendance Integrity

Traceability: `FR-HR-012`, `FR-HR-013`, `FR-HR-014`, `BR-004` (no self-approval), `NFR-06`, `NFR-10`; owner request of 2026-10-07 ("strengthen attendance"). Code: `AttendanceService`, `AttendanceAnomalies`, `AttendanceReviewService`, migration 122. This records what the application does; it is not a PRD edit.

## What a phone clock-in already checked

The person clocks in with their own account, within the distance of the property the owner set (default 100 m, measured by the phone's position), and, when the owner asks, with a selfie. A clock-in outside the distance, or without a position when a distance is set, is refused.

## What is added

Each clock-in and clock-out now keeps the **evidence** it came with:

| Kept | How | Why |
|---|---|---|
| Accuracy the phone claimed (metres) | the browser's `coords.accuracy` | a faked position is often reported as exactly right (1 m or better) |
| A fingerprint of a phone identifier | SHA-256 of a random id the browser keeps in local storage | one phone clocking in for several people |
| A fingerprint of the selfie | SHA-256 of the file's bytes | a camera never takes the same picture twice; a repeated one came from a gallery |

Nothing is refused because of these. They only **mark** a clock-in (worked out whenever it is read, from the stored evidence):

| Mark | Meaning |
|---|---|
| `shared_device` | the same phone clocked in for two or more people |
| `reused_photo` | the same selfie, byte for byte, twice |
| `same_spot` | five or more clock-ins by one person at exactly the same distance |
| `exact_position` | the phone claimed 1 m accuracy or better |
| `poor_position` | the phone's accuracy was worse than the allowed distance |
| `new_device` | the first clock-in from a phone after three or more from other phones |

## The review list (Attendance page, for people with `hr.attendance.manage`)

A marked clock-in of the last 14 days (marks are worked out over 45 days so repeats and shared phones show) is listed until a supervisor answers it **once**:

- **Looks fine**, or
- **Question it**, with a note of what looks wrong. Nothing in the attendance itself changes: if it must be corrected, the ordinary correction is asked for, which needs an approver (`hr.attendance-correction`).

The answer is stored in `hr_attendance_reviews`, never changed or deleted (the database refuses), and audited (`attendance.reviewed`). **A person does not answer about their own clock-in.** The supervisor can open the selfie (the existing, audited selfie download). Strongly marked clock-ins (`shared_device`, `reused_photo`) come first.

## What it does not do

- **No face is read, matched or scored.** The selfie is a picture a person looks at. Face matching would need new software (an approved decision), and a face is biometric data, which the Personal Data Protection Law (27/2022) treats as specific personal data needing the person's explicit consent. Not built, not planned without that decision.
- **A faked position is made harder to hide, not impossible.** A determined person with a mock-location app can still clock in; the marks make the usual ways visible to a supervisor.
- **A shared phone is not always cheating** (a family phone, a shared tablet at the staff entrance). That is why a person decides.

## Open

- **Retention of the evidence.** The fingerprints are kept with the attendance record. The selfie itself is deleted after 90 days; whether the device and photo fingerprints should be cleared on the same schedule is for the owner and counsel (`docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md`).
- Whether a `questioned` answer should open the correction request by itself.
