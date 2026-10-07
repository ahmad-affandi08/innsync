# Access Administration — People and Roles

Traceability: owner instruction of 2026-10-07 ("create accounts from a menu"), `BR-004` (maker-checker, no self-approval), `NFR-06` (authorization and property scoping), `NFR-22` (recent password confirmation for sensitive actions), `NFR-27` (accessibility). Code: `app/Modules/IdentityAccess/Application/Access/AccessAdmin.php`.

## Status of this document

The PRD has no functional requirement for screens that create accounts and roles; before this change an account was made only by `php artisan innsync:create-admin`. The owner asked for the screens on 2026-10-07. This document records the policy the implementation applies; it is not a PRD edit. The owner settled the remaining questions on 2026-10-07 (see "Decisions").

## Where

Property settings menu: **People & access** (`/access/users`) and **Roles** (`/access/roles`).

| Screen | Needs permission | Does |
|---|---|---|
| People & access | `identity.user.manage` | List people of the property with their roles; add a person; give or take away a role; deactivate or activate; reset the password |
| Roles | `identity.role.manage` | Create a role; choose its permissions and whether it requires two-step sign-in; change; deactivate or activate |

Both permissions are created by migration 118 and given to every role named `Administrator` (the role `innsync:create-admin` makes with all permissions). No other role receives them; an administrator gives them to a role by choosing them in the role editor.

## Rules

1. **No self-service.** A person never gives, takes away or changes their own roles, never deactivates or resets themselves, and never edits a role they hold (BR-004: no self-approval of one's own privileges).
2. **No escalation.** A role may be given, or built, only from permissions the person doing it holds at property scope. A permission they do not hold cannot be put in a role, cannot be taken out of one, and a role that carries one cannot be given by them.
3. **The `Administrator` role is the system's.** It is not edited or deactivated on screen, and its name is reserved.
4. **Never the last manager.** The last active person who holds `identity.user.manage` cannot be deactivated, revoked or have the role changed so as to lose it. (Rule 1 and 2 already make this rare; it is the safety net.)
5. **Nothing is deleted.** Roles are deactivated; assignments are switched off; accounts are deactivated. History and audit stay. A deactivated person is signed out at their next request and cannot sign in.
6. **Every change is audited** (`identity.account.created`, `identity.role.assigned`, `identity.role.revoked`, `identity.account.deactivated`, `identity.account.activated`, `identity.account.password-reset`, `identity.role.created`, `identity.role.updated`, `identity.role.deactivated`, `identity.role.activated`) with the actor, the property, the before and after, and the reason. A reason of at most 500 characters is required for every change. Passwords are never written to the audit trail.
7. **Recent password confirmation** is required for every write (HTTP 423 sends the person to confirm and back), and the writes are rate limited to 30 a minute per person and address.
8. **Property scope.** A role or assignment of another property is not found (404). An outlet scope must be an outlet of this property. Accounts are global (one login per person): an email that already has an account is refused, and this is final (decision below).

## Passwords

The server makes a temporary password (14 random characters plus a fixed tail so the strength rules always pass) and returns it once, with `Cache-Control: no-store`. It is not stored in clear text and not audited. The administrator hands it to the person. The person's account is flagged `must_change_password`: until they choose their own, every page except the password form, sign-out and the two-step checks redirects to **Account → Sessions**, which shows a notice. Changing the password clears the flag. A reset by an administrator ends every session of that person and sets the flag again.

## Decisions (owner, 2026-10-07)

- **One email, one account.** An email that already has an account is refused, always. A person is not shared between properties by adding the same email.
- **Two-step sign-in is not required of roles.** No role is forced to use it. The role editor keeps an optional switch (`roles.requires_mfa`, enforced at sign-in when it is on); people may still set it up themselves under their account.
- **No second approver for access changes.** Giving a role does not go through the approval inbox. If the owner later wants it, declare an approval subject such as `identity.role-assignment` in `config/approvals.php` and consume the request in `AccessAdmin::assignRole`.
- **No invitation by email, no department scope.** Not needed.

## Not in this change

- Inviting by email, self-registration and a "forgot password" email flow: the application sends no email for accounts; the administrator hands the temporary password over.
- Department scope for a role: only property and outlet scope are offered.
- Editing a person's name or email.

## Upgrading a running host

`php artisan migrate --force` (migration 118) adds the flag and the two permissions and gives them to the `Administrator` role of every property. After that, sign out and in again as the administrator and the menu entries open.
