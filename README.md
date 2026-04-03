# Teams Meeting ECP — Moodle Activity Plugin

**`mod_msteamsecp`**  
*Copyright (C) 2026 Eyecare Partners. All rights reserved.*  
*Licensed under GNU GPL v3. For internal use only.*

A ground-up rebuild of the Microsoft Teams meeting activity for Moodle/MapleLMS, built specifically for Eyecare Partners. Replaces the community `mod_msteams` plugin with a native Graph API integration, full lifecycle automation, and a significantly more capable feature set.

---

## Security Notice

The community `mod_msteams` plugin routes meeting creation through an external iframe pointed at a web service hosted and operated by Enovation (an Irish company). By default this is their hosted instance — originally `enovation.ie/msteams`, now migrated to `enomsteams.z16.web.core.windows.net` after the original URL broke. This introduces verified security and reliability concerns:

**Confirmed availability failures:** The Moodle plugin forum documents multiple outages of the Enovation-hosted service — including an SSL certificate expiry that broke meeting creation across all installations simultaneously, and a subsequent URL migration that required every affected site to manually update their configuration. As of the current plugin version (1.6), users on Moodle 4.5 and 5.0 are actively reporting "File not found" and "Not Found" errors when trying to create meetings through the iframe.

**Data sent to a third party:** When an instructor creates a meeting, the plugin sends your Moodle site URL (`$CFG->wwwroot`) and user language to the Enovation service as URL parameters. The instructor's Microsoft credentials are entered and processed inside the Enovation-hosted iframe. You have no visibility into what data is collected, logged, or retained.

**Compliance responsibility shifted to you:** The Enovation meetingapp repository explicitly states: *"You are responsible for complying with applicable privacy and security regulations related to use, collection and handling of any personal data by your app."* For a healthcare organization operating under HIPAA, routing staff Microsoft credentials and site data through a third-party service with no Business Associate Agreement is a material compliance risk.

**No privacy policy or DPA:** Enovation's privacy and terms of use URLs in their app manifest both point to `enovation.ie` — the same domain that has experienced DNS and SSL outages. No Data Processing Agreement is publicly documented.

`mod_msteamsecp` eliminates all of these concerns. All communication is directly between your MapleLMS server and Microsoft's Graph API over HTTPS. No third-party service is involved at any point in the meeting lifecycle. Your Azure credentials never leave your infrastructure, and there is no external availability dependency.

---

## Why This Plugin Exists

The community `mod_msteams` plugin works as follows: when an instructor creates a meeting activity, an iframe loads an external third-party web application hosted by Enovation. The instructor schedules the meeting inside that iframe, and the resulting Teams join URL is captured into a hidden form field and stored in Moodle as a plain URL — functionally identical to the built-in `mod_url` resource, which it is actually built on top of.

This architecture has significant limitations:

- **External dependency** — meeting creation depends on a third-party service outside your control
- **No Graph API integration** — Moodle never communicates with Microsoft directly; it only stores a URL
- **No meeting settings** — lobby bypass, recording, presenter settings must be configured manually inside Teams after creation
- **No automation** — nothing happens after the meeting is created; attendance, recordings, and calendar invites all require manual effort
- **No recurrence** — each session requires a separate activity
- **No completion intelligence** — completion is view-based only (clicking the link counts as complete)
- **One database table with 7 fields** — the entire plugin stores only an ID, course, name, intro, URL, and timestamp

`mod_msteamsecp` replaces all of this with a direct Microsoft Graph API integration that automates the full meeting lifecycle from creation through post-event credit.

---

## Feature Comparison

| Feature | mod_msteams (community) | mod_msteamsecp (ECP) |
|---|---|---|
| Meeting creation | Via external iframe at enovation.ie | Direct Graph API call — no external dependency |
| External service dependency | Yes — enovation.ie/msteams | None |
| Graph API integration | None | Full |
| Lobby bypass | Manual in Teams after creation | Per-meeting setting via Graph API; default organizers and co-organizers |
| Auto-record | Manual in Teams after creation | Toggle per meeting in Moodle form |
| Recurring meetings | Not supported | Daily / weekly / monthly with day-of-week selection |
| Facilitator assignment | Not supported | Required per meeting; co-organiser role synced via Graph PATCH |
| Enrollee calendar push | Not supported | Real Outlook/Teams invite sent on enrol; advances automatically until completion |
| Calendar removal on completion | Not supported | Automatic on course completion or unenrolment |
| Attendance tracking | Not supported | Automatic via Graph attendance reports |
| Completion by attendance % | Not supported | Configurable threshold per meeting |
| Recording retrieval | Not supported | Automatic download from OneDrive or manual upload |
| Recording delivery | Not supported | Auto-created video activity in Session Recordings section |
| Completion by recording | Not supported | Supported via Moodle activity completion |
| Post-event automation | None | Scheduled task every 15 minutes |
| Database tables | 1 (7 fields) | 5 (purpose-built schema) |
| Backup/restore support | Yes | Yes (v1.1+) |
| Privacy API (GDPR) | No | Yes (v1.1+) |
| Mobile app support | Yes | Planned |

---

## Features

### Meeting Creation via Graph API
Meetings are created programmatically via `POST /users/{id}/onlineMeetings` when the activity is saved in Moodle. No external service, no iframe, no manual URL copying. The instructor fills out a Moodle form and the meeting exists in Teams immediately.

### Lobby Bypass
Lobby access is configured per meeting via the Graph API. The default setting is **"Organizers and co-organizers"** — facilitators (who are assigned the co-organiser role via Graph PATCH) bypass the lobby automatically. Learners are held in the lobby until a facilitator admits them, which is the correct behaviour for a managed training session.

The lobby bypass setting can be changed per meeting if needed. Available options:

| Option | Who bypasses |
|---|---|
| Organizers and co-organizers *(default)* | Facilitators only |
| People I invite | Anyone sent a calendar invite |
| People in my organization and guests | All org members and guests |
| People in my organization (excluding guests) | Org members only |
| People in my organization, trusted organizations, and guests | Federated users included |
| Everyone | No lobby |

> **Note:** Teams Admin Center meeting policy settings on the service account can override per-meeting lobby settings. If lobby bypass is not working as expected, IT should verify that **"Let organizers override lobby settings"** is enabled in the policy assigned to the service account.

### Facilitator Assignment
At least one facilitator is required on every meeting — the form will not save without one. Facilitators receive a real Outlook/Teams calendar invite and are assigned the co-organiser role on the Teams meeting via Graph (`PATCH /onlineMeetings/{id}`), giving them full session control:

- Bypass the lobby automatically
- Start the meeting without the service account present
- Admit learners from the lobby (`allowedLobbyAdmitters: organizerAndCoOrganizers`)
- Manage recording
- End the meeting for all participants

The facilitator selector in the form is populated from enrolled users who hold a configurable set of Moodle roles (default: `editingteacher`, `teacher`, `manager`).

### Auto-Record
A per-meeting toggle (`recordAutomatically`) starts recording automatically when the meeting begins. `allowRecording` and `allowTranscription` are also set to `true` on all meetings. Works in conjunction with the recording retrieval pipeline.

> **Note:** Auto-record requires cloud recording to be enabled in the Teams meeting policy assigned to the service account. If recording does not start automatically, IT should verify **"Cloud recording"** and **"Who can record"** are configured permissively in the policy.

### Recurring Meetings
Full recurrence support with daily, weekly, and monthly patterns. Weekly recurrence supports specific day-of-week selection (e.g. every Monday and Wednesday). Series can end by date or occurrence count. Each occurrence is tracked individually with its own attendance report and recording.

### Recording Behavior (Recurring)
For recurring meetings, recordings can either be appended (one recording activity per occurrence, building an archive) or replaced (the recording activity is updated in place, always showing the most recent session).

### Enrollee Calendar Push — Rolling Single-Occurrence Model
When a user enrols in a course containing a Teams meeting activity, the plugin pushes **only the next upcoming occurrence** as a real Outlook/Teams invitation — not the entire series. This keeps calendars clean and avoids showing a year's worth of sessions at once. The learner receives an email notification and a Teams calendar notification.

After each occurrence ends and attendance is processed, the plugin automatically pushes the next occurrence to any enrolled user who has not yet earned completion credit. This continues until the user attends a qualifying session (triggering course completion) or is unenrolled — at which point future calendar invites are removed and a cancellation is sent.

**The full lifecycle for a recurring orientation-style event:**
1. Learner enrols → one occurrence appears on their Teams calendar with a proper invite
2. They attend and meet the threshold → credit granted → course complete → future events removed
3. They don't attend (or don't meet the threshold) → next occurrence invite pushed automatically
4. Steps 2–3 repeat until they complete or are unenrolled

### Attendance Tracking
After each meeting ends (with a 20-minute grace period for Teams to generate the report), a scheduled task fetches the attendance report from Graph. Per-user join time, leave time, total duration, and attendance percentage are stored per occurrence. No manual effort required.

### Completion by Attendance
Each meeting has a configurable attendance threshold (0–100%). Setting it to 0 grants credit for any join. Setting it to 75 requires the user to have been present for at least 75% of the meeting duration. Credit is granted automatically — no manual grading.

### Recording Retrieval
After each meeting ends, the scheduled task checks for a recording in Graph (`GET /onlineMeetings/{id}/recordings`). When available, it downloads the MP4 and creates a Moodle `resource` activity in the designated recordings section of the course. Works for automatic recordings; manual upload is also supported for recordings captured outside the automated flow.

> **Note:** Recording retrieval via Graph may not work reliably for meetings created via `POST /onlineMeetings` (standalone meetings without a calendar event). If recording retrieval fails consistently, this is a known Graph API limitation — see Known Limitations below.

---

## IT Admin Setup

### Azure App Registration

1. Create an app registration in Azure Active Directory
2. Add the following **Application permissions** (not Delegated) and grant admin consent:

| Permission | Purpose |
|---|---|
| `OnlineMeetings.ReadWrite.All` | Create, update, delete Teams meetings |
| `Calendars.ReadWrite` | Create and manage calendar events for invites |
| `OnlineMeetingRecording.Read.All` | Retrieve meeting recordings |
| `OnlineMeetingTranscript.Read.All` | Retrieve meeting transcripts |
| `User.Read.All` | Resolve facilitator email addresses to AAD object IDs |

3. Create a client secret and note the Tenant ID, Client ID, and Client Secret for plugin configuration

### Service Account

Create a dedicated Microsoft 365 user account (e.g. `mapleLMS@yourcompany.com`) with:
- A Teams license (required for meeting creation)
- An Exchange Online mailbox (required for calendar event creation)
- A permissive Teams meeting policy (see below)

This account is the technical organiser of all meetings. It never joins meetings in person.

### Application Access Policy (Teams PowerShell)

This step is **mandatory** — without it, all Graph API calls for online meetings will return 403 Forbidden.

```powershell
# Step 1 — Create the policy
New-CsApplicationAccessPolicy `
    -Identity "MapleLMSMeetingPolicy" `
    -AppIds "your-app-client-id-here" `
    -Description "Allow MapleLMS plugin to manage Teams meetings"

# Step 2 — Grant to the service account (use the AAD Object ID, not UPN)
Grant-CsApplicationAccessPolicy `
    -PolicyName "MapleLMSMeetingPolicy" `
    -Identity "service-account-aad-object-id"
```

> **Propagation:** Policy changes can take 30 minutes to several hours to take effect. Always wait at least 30 minutes before testing after making policy changes.

### Teams Meeting Policy

The following settings must be configured in the Teams Admin Center meeting policy assigned to the service account:

| Setting | Required Value |
|---|---|
| Cloud recording | On |
| Who can record | Organizers and co-organizers (or Everyone) |
| Let organizers override lobby settings | On |
| Auto-record | On (or leave off and use per-meeting setting) |

Without **"Let organizers override lobby settings"** enabled, the per-meeting lobby bypass configured by the plugin will be ignored and the policy value will be enforced instead.

---

## Plugin Configuration

**Site Administration → Plugins → Activity modules → Teams Meeting (ECP)**

| Setting | Description |
|---|---|
| Azure Tenant ID | Your M365 tenant GUID |
| Client ID | Azure app registration client ID |
| Client Secret | Azure app registration client secret |
| Service Account UPN | UPN of the shared organiser account e.g. `mapleLMS@yourcompany.com` |
| Facilitator roles | Comma-separated Moodle role shortnames eligible to be selected as facilitators. Default: `editingteacher,teacher,manager` |
| Default lobby bypass | Default "Who can bypass the lobby" for new meetings. Default: Organizers and co-organizers |
| Auto-record by default | New meetings record automatically unless changed per meeting |
| Default recording mode | Manual or automatic for new meetings |
| Default attendance threshold % | Default minimum attendance for completion credit (0 = any join) |
| Recordings section name | Name of the auto-created course section. Default: `Session Recordings` |

---

## Instructor Setup — Recurring Events (e.g. New Hire Orientation)

For events where learners only need to attend once but the event recurs on a regular schedule, configure the activity as follows:

| Setting | Recommended Value |
|---|---|
| Meeting type | Recurring (daily / weekly / monthly as appropriate) |
| Lobby bypass | Organizers and co-organizers |
| Auto-record | On (allows learners who miss their occurrence to watch the recording) |
| Attendance threshold | 75% (adjust to your policy — 0 grants credit for any join) |
| Activity completion | Enable, set to complete when attendance credit is granted |
| Course completion | Complete when this activity is complete |

With this configuration the plugin handles the full lifecycle: the learner sees one upcoming session on their Teams calendar, attends when ready, earns credit, and the calendar event is removed. Learners who miss a session are automatically advanced to the next one with no manual intervention required.

---

## Architecture

```
mod_msteamsecp/
├── db/
│   ├── install.xml          # 5 tables: instances, occurrences, attendance, enrollee_events, coorganisers
│   ├── access.php           # Capabilities: view, addinstance, uploadrecording, viewattendance
│   ├── upgrade.php          # Upgrade path v1.0 → v1.3
│   └── tasks.php            # Scheduled task: post-event processor (every 15 min)
├── classes/
│   ├── api/
│   │   └── graph.php        # Graph API client — meetings, events, attendance, recordings
│   ├── sync/
│   │   ├── meeting_creator.php      # Meeting creation, update, delete, facilitator sync
│   │   ├── enrolment_handler.php    # Rolling calendar push on enrol; removal on complete/unenrol
│   │   └── post_event_processor.php # Attendance, completion, recording, next-occurrence advance
│   ├── task/
│   │   └── process_events.php       # Scheduled task wrapper
│   ├── privacy/
│   │   └── provider.php             # GDPR Privacy API — data export and deletion
│   └── event/
│       └── course_module_viewed.php # Moodle event for view tracking
├── backup/moodle2/          # Backup and restore support
├── lang/en/
│   └── msteamsecp.php       # All UI strings
├── mod_form.php             # Meeting creation/edit form
├── lib.php                  # Moodle hooks: add/update/delete instance, enrolment observers
├── view.php                 # Learner-facing activity view
├── index.php                # Course-level meeting list
├── ical.php                 # iCal (.ics) download endpoint
├── settings.php             # Plugin admin settings
└── version.php
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `msteamsecp` | One row per activity instance — Graph IDs, settings, recurrence config |
| `msteamsecp_occurrences` | One row per occurrence — status, recording cmid, attendance fetch state |
| `msteamsecp_attendance` | One row per user per occurrence — join/leave times, duration %, credit |
| `msteamsecp_enrollee_events` | One row per user per occurrence — Graph calendar event ID, removal state |
| `msteamsecp_coorganisers` | Manually selected facilitators per meeting instance |

---

## How the Scheduled Task Works

The `process_events` task runs every 15 minutes and handles four things in order:

1. **Mark ended occurrences** — any occurrence past its end time is moved to `ended` status
2. **Fetch attendance** — for ended occurrences (with a 20-minute grace period), fetches the Graph attendance report and stores per-user records; grants completion credit where the threshold is met
3. **Retrieve recordings** — for ended auto-record occurrences, checks Graph for a completed recording and creates a Moodle resource activity when available
4. **Advance calendar push** — for each ended occurrence, pushes the next upcoming occurrence to all enrolled users who have not yet earned completion credit

---

## Known Limitations

- Moodle mobile app not yet supported
- Graph does not allow PATCH on the calendar event time or subject after creation for Teams online meeting-linked events (returns 405). If a meeting time changes significantly, delete and recreate the activity
- Attendance and recording retrieval via Graph may not work reliably for standalone meetings created via `POST /onlineMeetings` that are not associated with a calendar event. This is a documented Graph API limitation — Microsoft recommends the Calendar Events API for reliable artifact access. If attendance or recording retrieval fails consistently, this is the likely cause
- Teams Admin Center meeting policy settings on the service account can override per-meeting API settings for lobby bypass and recording. If settings applied by the plugin are not being honoured at runtime, IT should check the policy assigned to the service account and ensure organizer override is permitted

---

## Version History

| Version | Notes |
|---|---|
| 1.0.0 | Initial release — full Graph API integration, recurring meetings, attendance tracking, recording pipeline, calendar push/removal, co-organiser sync |
| 1.1.0 | Added Privacy API (GDPR data export and anonymised deletion), backup/restore support, upgrade path, manual co-organiser selection per meeting |
| 1.1.1 | Rolling calendar push — enrol pushes next occurrence only; post-attendance processor advances incomplete users one occurrence at a time |
| 1.2.0 | Added co-organiser role sync via Graph PATCH; iCal download endpoint; Boost child theme compatibility fixes |
| 1.3.0 | Per-meeting lobby bypass setting restored; facilitator label throughout UI; `allowedLobbyAdmitters` set; `allowRecording`/`allowTranscription` always true; enrollee calendar push switched to real Outlook/Teams invitations via service account calendar with `sendUpdates=all`; PATCH and DELETE fixed to use Moodle curl `->patch()` and `->delete()` correctly |
| 1.4.0 | Dual-token architecture — meeting creation and update use a delegated (user) token via `/me/onlineMeetings` so Teams treats meetings as user-created, giving facilitators full co-organiser permissions immediately without the service account joining. All other calls (attendance, recordings, calendar events, user lookups) continue using the app-only token. One-time OAuth 2.0 authorization flow via plugin settings page; refresh token stored encrypted using AES-256-CBC; automatic silent refresh. Falls back to app-only token if no delegated token is configured. |

---

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE) for full terms.

Copyright (C) 2026 Eyecare Partners. This software was developed for internal use at Eyecare Partners. Distribution outside of Eyecare Partners is not authorized except as required by the terms of the GNU GPL v3.
