# Authentication & Access Control

## The central decision: two tables, two guards

Customers live in `users`. Staff live in `admins`. They are different models,
authenticate through different guards, and reset passwords through different
brokers.

This is deliberate, and it is the security property everything else rests on.
With a single table plus an `is_admin` flag, privilege escalation is a
one-column write — reachable through a mass-assignment bug, a careless
`update()`, or any compromised customer-facing endpoint. With two tables, there
is no code path from a customer record to an administrator: becoming staff
requires inserting a row into a table the customer-facing code never writes to.

Phase 1 seeded `users.is_admin` with a note that it would be "replaced by a
role/permission model in the authentication phase". That replacement is this
phase, and the column is **dropped** rather than deprecated — leaving a
writable boolean that once meant "is an administrator" invites a future bug.

| | Customers | Staff |
|---|---|---|
| Table | `users` | `admins` |
| Session guard | `web` | `admin` |
| Token guard | `sanctum` | `admin-api` |
| Reset tokens | `password_reset_tokens` | `admin_password_reset_tokens` |
| Token ability | `customer:access` | `admin:access` |
| Token TTL | 7 days | 8 hours |
| Self-registration | yes | **never** |

Separate reset-token tables matter more than they look: a shared table would
let a reset token issued for a customer be redeemed against a staff account
holding the same email address. There is a test for exactly this.

## Defence in depth on the realm boundary

A customer token reaching an admin route fails three independent checks:

1. **The guard's provider queries a different table** — `admin-api` resolves
   against `admins`, so a token whose `tokenable_type` is `User` has no
   principal at all.
2. **Token abilities are disjoint** — `customer:access` is not `admin:access`.
3. **`EnsureAdminIsActive` asserts `$user instanceof Admin`** — so a future
   routing mistake produces a 403 rather than an escalation.

## Permission resolution

```
Super Admin?  ──yes──>  every permission (Gate::before bypass)
     │no
     ▼
union of all permissions granted by the admin's roles
     +  direct grants   (admin_permission.is_granted = true)
     −  direct revokes  (admin_permission.is_granted = false)   ← wins
```

Revokes are applied last, so an exception can *subtract* from a role rather
than only add to it — "this Product Manager may not delete products" is
expressible without inventing a bespoke role.

**Super Admin is a bypass, not a seeded permission list.** Modelling it as
`Gate::before` means a newly added permission is available to Super Admin
immediately, with no window in which the top role silently lacks a new
capability. The seeded `super_admin` role deliberately has zero rows in
`permission_role`.

**The bypass has two exceptions**: the `delete` and `activate` abilities fall
through to `AdminPolicy` even for a Super Admin. Those policy methods enforce
the self-protection rules — you cannot delete or deactivate your own account —
and those rules must bind Super Admins most of all, since they are the only
accounts capable of locking everyone out. A blanket bypass would let a Super
Admin delete themselves and strand the installation.

### Caching

Resolved permission sets are cached per admin (`permissions:admins:{id}`), with
an in-request memo on top. An authorization check runs on nearly every admin
request, often several times, and resolving it walks two pivot tables.

The TTL is only a backstop. `flushPermissionCache()` fires on every role or
permission change, so a revocation takes effect on the *next request* — not an
hour later. A stale permission cache is the failure mode that makes permission
caching dangerous, and there is a test asserting revocation is immediate.

## Roles

| Role | Level | Holds |
|---|---|---|
| Super Admin | 100 | Everything, by bypass |
| Admin | 80 | All non-privileged permissions |
| Manager | 60 | Catalog, orders, reporting |
| Order Manager | 40 | Orders, refunds, payments (read) |
| Product Manager | 40 | Catalog only |
| Content Manager | 40 | Settings, menus, banners |
| Support Staff | 20 | Read-mostly, plus support tickets |

`manage_admins` and `manage_roles` are marked **privileged** and excluded even
from the Admin role. Granting `manage_roles` is functionally equivalent to
granting every permission, because the holder can edit their own role — so it
stays with Super Admin.

### The level hierarchy

Levels stop `manage_admins` from being a blank cheque. Without them, anyone
holding it could delete the Super Admin and seize the installation.

- An actor must **strictly** outrank a target to modify, delete, or deactivate
  it. Equal rank is refused, so two Admins cannot delete each other in a race.
- Nobody may assign a role at or above their own level.
- Nobody may grant a permission they do not themselves hold — otherwise
  `manage_roles` becomes a path to everything via a puppet account.
- The last active Super Admin cannot be deleted, deactivated, or demoted.
- Nobody may delete or deactivate their own account.

The first three cannot be expressed in a policy (which never sees the requested
role list), so they live in `AdminManagementService`. Authorization therefore
runs at three layers:

```
Route middleware  →  does this account hold the permission at all?
Policy            →  may this actor act on THIS target, given their ranks?
Service           →  do the requested roles/permissions stay within what the
                     actor may delegate?
```

## Account enumeration

Three endpoints are careful not to reveal which email addresses are registered:

- **Login** runs `Hash::check` against a dummy hash when no user is found, so
  response timing does not differ between "no such account" and "wrong
  password". Both return an identical error.
- **Forgot password** returns the same success message either way. There is
  deliberately no `exists:users,email` validation rule — it would turn the
  endpoint into an oracle, with 422 meaning "no account" and 200 meaning
  "account exists".
- **Reset password** reports invalid and expired tokens identically.

**The throttle result is also suppressed**, which is subtler than it looks.
Laravel's broker can only throttle an address it actually *found*, so a
registered address returns `RESET_THROTTLED` on a rapid second request while an
unregistered one silently returns `INVALID_USER`. Surfacing the throttle as a
422 therefore reintroduces the exact leak the generic message exists to
prevent. The endpoint returns 200 regardless; only one email is sent, so the
mailbox protection is real even though the response never changes.

This was caught by a test asserting that a throttled known address and a
throttled unknown address respond identically — the earlier implementation
failed it.

## Token lifecycle

| Event | Effect |
|---|---|
| Logout | Revokes the current token only — other devices stay signed in |
| Logout all | Revokes every token |
| Password change | Revokes all **other** tokens; spares the current one |
| Password reset | Revokes **every** token, including the current |
| Deactivation | Revokes every token immediately |
| Email verified | Revokes tokens so the next sign-in mints a full-access one |

A password reset is the recovery path after a suspected compromise, so sparing
any session would leave an attacker signed in after the owner "fixed" it. A
password *change* spares the current device because the user is standing there.

## Email verification

Registration issues a token immediately, with the narrow `customer:unverified`
ability. That token can read the profile and request a new link — nothing else.
A user forced to log in before being told "verify your email" has no way to
understand why their account does not work.

The verification link is a Laravel signed URL. It hits the API (which must
verify the signature) and then **redirects to the storefront** — returning JSON
to a browser address bar would show the user a wall of raw text. The hash
component is `sha1(email)` rather than the address itself, so the link does not
leak the email through a referrer header or a shared screenshot.

## Forced password rotation

An account created without an explicit password gets a generated one and
`must_change_password = true`. That password has necessarily passed through a
third party — read off a screen, pasted into chat, printed to a CI log — so it
must not survive as a long-term credential.

`EnsurePasswordIsCurrent` 403s every admin endpoint with
`PASSWORD_CHANGE_REQUIRED` until it is satisfied, exempting only
`me`, `logout`, and `change-password` — without those exemptions the
requirement would be impossible to fulfil.

## Frontend

**Route protection is usability, not security.** `AdminGuard` and the
permission-filtered navigation exist so users do not see dead buttons and
broken shells. Every endpoint enforces the same rules server-side, so editing
`sessionStorage` in devtools yields menu items that return 403 when clicked.

Two details worth knowing:

- **Guards wait for store rehydration.** Acting on a null token before
  sessionStorage has been read would bounce a signed-in user to the login page
  on every refresh. `isHydrated` gates the redirect.
- **`next` redirect targets are validated.** Only same-origin relative paths
  are honoured (and for admin, only `/admin*`). Accepting an absolute URL would
  make login an open redirect usable in phishing.

### Token storage

Tokens live in **sessionStorage**, not localStorage. A token in localStorage
survives browser restarts and is readable by any script on the origin, so an
XSS bug becomes a lasting account compromise rather than a tab-lifetime one.

This is a mitigation, not a solution. The proper hardening — out of scope for
this phase — is httpOnly cookies with CSRF protection, which JavaScript cannot
read at all.

## Rate limiting

| Limiter | Limit | Key |
|---|---|---|
| `auth` | 5/min + 20/min | email+IP, then IP |
| `admin-auth` | 5/min + 10/min | email+IP, then IP |
| `password-reset` | 3 per 15 min | email+IP |
| `verification` | 5 per 15 min | user or IP |
| `authenticated` | 120/min | model class + id |

Credential endpoints carry **two** limits. Keying on IP alone would let a
distributed attack try one password per address indefinitely; keying on email
alone would let an attacker lock a victim out of their own account by
deliberately failing. The pair limits both without either failure mode.

The `authenticated` limiter keys on `ModelClass:id` so a `User` and an `Admin`
sharing a primary key do not collide.

## Adding a permission

1. Add the case to `App\Enums\PermissionType`, with a `label()` and `group()`.
2. Add it to the relevant roles' `defaultPermissions()` in `RoleType`.
3. Run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
4. Guard the route: `->middleware('permission:your_permission')`.
5. Add the string to `PermissionName` in `frontend/src/features/auth/types`.

Step 5 keeps `useCan('typo_here')` a compile error rather than a menu item that
silently never appears.

The seeder **prunes** permissions removed from the enum, so a stale row cannot
linger and be granted. It refreshes role metadata but never overwrites an
existing role's permission set — re-seeding must not silently revert an
operator's deliberate changes.
