# Module 14 — Quiet, silent and push-delivery policy (Firebase OFF)

**Prepared:** 2026-11-02. **Status:** provider disabled; this is a policy and non-provider code baseline, not a device certification.

## Transport vs presentation
- **In-app HTTPS inbox:** source-of-truth, private owner-bound unread/read, read-on-open and current ACL. Works when push is OFF. Server templates use generic safe copy.
- **Firebase data-only push:** notification_available is only a silent *invalidation hint*, containing no user IDs, message text, Circle membership, location, encryption keys, authentication data or notification target. FCM HTTP acceptance is not proof that the device received, displayed, opened or read anything.
- **Foreground:** a verified, opted-in, trusted device with a configured provider may request unread refresh. The current generic data-only signal does not automatically create a local OS-visible card.
- **Background/terminated:** Android/iOS may delay, coalesce, suppress or never execute the data-only callback due to OS quotas, battery policy, user permission, force-stop or APNs delivery. The next authenticated foreground or explicit inbox refresh reconciles the server state.
- **Quiet/silent:** while push is disabled all events are silent to the OS. With push enabled, the current generic data-only transport has no alert/sound/badge payload, so the OS may not show anything even if sound/vibration M3 preferences are enabled. A user OS focus/Do Not Disturb, quiet hours and permission controls remain authoritative; never bypass them. No scheduled loud reminder and no asserted exact delivery time.
- **Server user mutes:** eligible Circle/conversation push is suppressed when owner mutes or revokes access. Muting alone does not make an otherwise authorized generic in-app notice disappear unless the source policy says it must. Security notices cannot be disabled through optional Messages/Friends settings.
- **Accessibility:** semantic titles, read states, group item count and a distinct account-security label are provided; Android TalkBack and iOS VoiceOver behavior require device QA.
- **Safety:** SOS/critical escalation and time-critical notification entitlements are outside M14. M14 generic notifications must never claim critical/emergency delivery.

## Firebase activation is a separate approved step
1. Configure real Firebase project, FCM HTTP v1 backend service account outside web root, Android app ID/SHA, iOS bundle/APNs credentials; never commit secrets or send private keys through a public chat.
2. Validate provider, trusted device, Android 13+ POST_NOTIFICATIONS and iOS permission on actual hardware. Ensure M7/M14 cutover does not double-send.
3. If the product requires OS-visible alerts, define generic local display behavior and review notification channel(s), sound/vibration policy, icon, tap and cross-platform background restrictions before rollout.
4. Enable provider switch only after successful test evidence and explicit release approval. Leave ORBIT_NOTIFICATIONS_PUSH_ENABLED=false until then.

This policy deliberately does **not** claim silent delivery guarantees, OS tray visibility, or certified Firebase support.
