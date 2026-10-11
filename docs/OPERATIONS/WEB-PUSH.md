# Notices on devices (web push)

A person can allow notices on a phone or computer (Account → Sessions and password → *Notices on this device*). Today an approval waiting for them, a work order given to them or nearing or passing its deadline, and a request from a guest (to those who may work on guest requests) are shown there. Routes: `GET /push/config`, `POST` and `DELETE /push/devices`, `GET /push/next`. Tables `push_devices`, `push_notices` (migration 145). The service worker (`public/sw.js`, version `innsync-v3`) shows them.

## How it works, and why there is no library

The push services (Google, Mozilla, Apple) are third parties. So the push itself carries **no words**: the server tells the service "something waits for this device" with an empty message, and the service worker on the device then asks this server (`GET /push/next`, behind the person's own sign-in, naming the device in the `X-Push-Endpoint` header) what the notice is, and shows it. If the server cannot be reached it shows a plain line, "Something needs your attention". The notice is kept for each device and handed out once.

Because the message is empty it is not encrypted, and the only cryptography needed is the signed token that identifies the sender (VAPID, RFC 8292: ES256 over P-256, done with PHP's `openssl`). So **no package was added** (the usual library, `minishlink/web-push`, would bring seven; it is needed only to send words). Should the hotel one day want the words in the push itself, that library is the way, as a documented decision.

## Setting it up

1. `php artisan innsync:push-keys` makes the key pair and prints two lines.
2. Put `VAPID_PRIVATE_KEY=…` and `VAPID_SUBJECT=mailto:an-address-of-the-hotel` in the environment. Keep the pair: devices that allowed notices with a key stop receiving them if it changes (they have to turn them on again).
3. The page then shows the switch. Until then nothing is shown and nothing is sent.

On an iPhone or iPad notices work only for the app added to the home screen (iOS 16.4 or later); the page says so where the browser cannot.

## Rules

- **Who.** Only a person who turned notices on, on that device. Up to 5 devices a person (`PUSH_MAX_DEVICES`). A device is dropped when the push service says it is gone, or after 20 failures in a row. Signing a device in as another person moves it to that person.
- **Where it is sent.** Only to the addresses of known push services (`config/push.php`, `allowed_hosts`): the address is typed by a browser, and the server must never be pointed at an address of a person's choosing. The address of a device is kept sealed.
- **What it says.** The same plain words as the e-mail notices: an approval waits (nothing about its subject, amount or maker), a work order's number, priority and place (never its title or a guest). The place to open is a path of this application only.
- **Kinds.** A person can turn off a kind of notice for themselves (*Tell me about*: approvals, work orders, guest requests; every kind is on until then); it holds for all their devices. A guest request notice names the room, the kind of request and whether it is urgent, never the guest or what was written, and the person who made the request is not told of it.
- **Alongside e-mail.** Push is sent whenever it is set up; the e-mail notices stay behind `APPROVAL_EMAIL_NOTICE` and `MAINTENANCE_EMAIL_NOTICE`.
- **Waiting notices** are kept 3 days (`PUSH_NOTICE_DAYS`) and then removed.

## Not done

- Tried on real devices and the real push services: the tests use faked answers and a fake of the service worker's environment. Check with a phone before relying on it.
- Other things to tell people about (low stock, a cashier shift, a restaurant order), and quiet hours.
- Words in the push itself (see above).
