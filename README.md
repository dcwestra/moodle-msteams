# ECP Teams Meeting — Moodle Activity Plugin

**`mod_msteamsecp`**  
*Copyright (C) 2026 Eyecare Partners. All rights reserved.*  
*Licensed under GNU GPL v3. For internal use only.*

A ground-up Microsoft Teams meeting activity for Moodle, built specifically for Eyecare Partners. Replaces the community `mod_msteams` plugin with a native Graph API integration, full lifecycle automation, and a significantly more capable feature set.

---

## Security Notice

The community `mod_msteams` plugin routes meeting creation through an external iframe hosted by Enovation (an Irish company), introducing verified reliability and compliance risks — multiple documented outages, third-party exposure of Microsoft credentials, and no Data Processing Agreement.

`mod_msteamsecp` eliminates all of these. All communication is directly between your Moodle server and Microsoft's Graph API over HTTPS. No third-party service is involved at any point. Your Azure credentials never leave your infrastructure.

Credentials (Client ID and Client Secret) are stored as plaintext in Moodle's config table, protected by AWS RDS encryption at rest (AES-256) and Moodle's own access controls. They are masked in the UI and never returned to the browser.

---

## Feature Comparison

| Feature | mod_msteams (community) | mod_msteamsecp (ECP) |
|---|---|---|
| Meeting creation | Via external iframe (enovation.ie) | Direct Graph API — no external dependency |
| External service dependency | Yes | None |
| Graph API integration | None | Full |
| Lobby bypass | Manual in Teams | Per-meeting setting, organizers/co-organizers by default |
| Auto-record | Manual in Teams | Toggle per meeting |
| Recurring meetings | Not supported | Daily / weekly / monthly with day-of-week selection |
| Co-organiser assignment | Not supported | Required per meeting; co-organiser role synced via Graph |
| Enrollee calendar push (Outlook/Teams) | Not supported | Real Outlook/Teams invite on enrol |
| Moodle LMS calendar | Not supported | Automatic — appears on dashboard and calendar page |
| Calendar removal on completion | Not supported | Automatic — removed on activity or course completion |
| Attendance tracking | Not supported | Automatic via Graph attendance reports |
| Attendance percentage | Not supported | Based on actual meeting duration, capped at 100% |
| Attendance requirement | Not supported | Attend once (any) or attend all sessions |
| Completion by attendance % | Not supported | Configurable threshold |
| Recording retrieval | Not supported | Automatic — stored in Moodle, inline player in activity |
| Completion by recording | Not supported | Automatic at configurable watch % (default 80%) |
| Post-event automation | None | Scheduled task every 15 minutes |
| Join button timing | N/A | Available 15 minutes before start only |
| Activity completion API | Not supported | Full custom_completion class (Moodle 4.2+ standard) |
| IOMAD compatibility | Not tested | Compatible |
| Database tables | 1 (7 fields) | 6 (purpose-built schema) |
| Backup/restore | Yes | Yes (backup files included, FEATURE_BACKUP_MOODLE2 enabled) |
| Privacy API (GDPR) | No | Yes |

---

## Features

### Meeting Creation via Graph API
Meetings are created directly via the Graph API when the activity is saved. The service account uses a delegated OAuth token so Teams recognises the meeting as user-created, giving co-organisers full permissions immediately.

### Delegated OAuth Token
Two-token architecture:
- **Delegated token** — meeting creation and update. Obtained via a one-time OAuth 2.0 authorization flow in plugin settings. Stored AES-256-CBC encrypted. Silently refreshed. Settings page warns at 75 days; re-authorization required at 90 days.
- **App-only token** — all other Graph calls (attendance, recordings, calendar events, user lookups).

### Lobby Bypass
Configured per meeting. Default: **Organizers and co-organizers** — co-organisers bypass automatically, learners wait until admitted.

> Requires Teams Admin Center meeting policy to permit organizer override of lobby settings.

### Co-organiser Assignment
Required on every meeting. Co-organisers receive a real Outlook/Teams calendar invite and hold the co-organiser role in Teams — bypass lobby, start meeting, admit from lobby, manage recording, end meeting. Existing co-organisers are pre-populated when editing an activity.

### Auto-Record
Per-meeting toggle. Recording starts automatically when the meeting begins.

> Requires `AllowCloudRecording` and `AutoRecording` enabled in the Teams meeting policy on the service account.

### Recurring Meetings
Daily, weekly (with day-of-week selection), and monthly recurrence. Each occurrence is tracked individually for attendance and recording.

A series is defined by **how many occurrences it runs for** — "Number of occurrences", counting the first session. A weekly meeting starting 31 July with 3 occurrences runs on 31 July, 7 August and 14 August. Maximum 200.

The older "ends on date" option was removed in 1.7.2. It made the resulting number of sessions non-obvious, which invited repeated re-saving to get the count right — and re-saving used to duplicate occurrences. Existing meetings that used an end date are converted to the equivalent occurrence count automatically on upgrade.

Saving a recurring activity is now idempotent: occurrence rows are reconciled against the schedule rather than rebuilt, so saving any number of times produces exactly one row per session. Editing a series keeps attendance history for sessions that have already run, and keeps existing calendar invites for sessions whose time hasn't changed.

### Attendance Requirement
Controls the completion model for recurring meetings:

| Setting | Attend at least one session | Attend all sessions |
|---|---|---|
| Completion | Credit on any single session | Credit required on every ended session |
| Calendar invites | Rolling — next occurrence only; advances after each session | All upcoming occurrences sent at enrolment |
| Recordings | Replace — only most recent shown | Append — every session recording kept |
| View (recurring) | Last recording + next upcoming session | Full occurrence table with collapsible inline players |

For single (non-recurring) meetings this setting has no effect.

### Attendance Percentage
Attendance is calculated from the Graph attendance report using each participant's actual join/leave intervals. The denominator is the **scheduled meeting duration** (occurrence end time minus start time) — not the actual run time from the report. Using actual run time penalised on-time attendees when hosts joined early or ran late. Attendance is capped at 100%.

Teams issues a **separate attendance report each time a meeting session restarts** (the last participant leaves and someone rejoins), and for a recurring meeting the online meeting ID is series-level — so Graph returns reports for every occurrence held so far, in no guaranteed order and across multiple pages. Each report is therefore matched to the occurrence its meeting start time is nearest to, and every report belonging to an occurrence is merged into one set of intervals per attendee. Overlapping presence is unioned so a participant reported in two sessions is counted once, not twice.

Attendance is re-polled for 6 hours after an occurrence ends, so reports Teams publishes later than the 20-minute grace period are still picked up. Re-polling only ever adds — recorded duration, percentage and granted credit are never reduced by a later read. Attendees whose Teams address matches no Moodle account, and anonymous or dial-in participants who have no address at all, are named in the cron log rather than dropped silently.

### Recording Pipeline
After a meeting ends, the scheduled task checks for a completed recording, downloads it via Graph, stores it in Moodle's file system under the `mod_msteamsecp` component, and renders it as an inline HTML5 video player on the activity page. No separate course activity is created.

Learners earn completion credit by accumulating **unique watch time**: the player never blocks seeking, and counts each second of the recording once — rewatching a section doesn't double-count, and skipping over a section doesn't count the skipped part. When unique watch time reaches the threshold percentage of the recording's duration, credit is granted automatically. The threshold can be set per activity ("Minimum watch % required" in the completion settings; blank uses the site-wide default of 80%) — set it lower for recordings that contain meeting breaks or dead time so learners can skip those sections and still qualify. Learners who already have credit (live attendance, a previously watched recording, or a manual grant) see a review notice and are not tracked.

**Progress is persistent.** Watched sections are saved to the server every 30 seconds of playback (and on pause or when the tab is hidden), so long recordings can be watched across multiple sittings and devices — progress from different sessions is merged. Playback resumes where the learner left off, and a progress line under the player shows the percentage watched so far.

### Moodle LMS Calendar
When an activity is saved, Moodle internal calendar events are created for all upcoming occurrences. These appear on:
- The course calendar
- The site calendar
- The **Upcoming events** block
- The **Dashboard / Timeline** block (`block_myoverview`)

The Dashboard action becomes clickable 15 minutes before the meeting starts. Events disappear from the Dashboard once a learner earns completion credit. Calendar events are kept in sync when the activity is edited and cleaned up when it is deleted.

### Outlook/Teams Calendar Push
On enrolment, each learner receives a direct calendar invite to their Outlook/Teams calendar with the join link embedded. Co-organisers receive a separate invite at meeting creation time.

### Join Button Timing
The join button is only shown within 15 minutes of meeting start. Before that window learners see "Join opens in X" and an Add to Calendar button.

### Activity Completion
Implements the Moodle 4.2+ `custom_completion` class (`mod_msteamsecp\completion\custom_completion`). Two configurable completion rules:
- **Attend live** — learner attended at least the configured percentage of the actual meeting duration
- **Watch recording** — learner watched at least the configured percentage of the recording

Both rules respect the **attend once / attend all** setting for recurring meetings.

---

## Requirements

- Moodle 4.2 or later
- PHP 8.2 or later
- Microsoft 365 tenant with Teams
- Azure AD app registration with the following **Application** permissions (admin consented):
  - `OnlineMeetings.ReadWrite.All`
  - `Calendars.ReadWrite`
  - `OnlineMeetingRecording.Read.All`
  - `User.Read.All`
- And the following **Delegated** permissions (user consented via OAuth flow):
  - `OnlineMeetings.ReadWrite`
  - `Calendars.ReadWrite`
  - `User.Read.All`
- Azure app registration redirect URI: `https://your-moodle/mod/msteamsecp/oauth_callback.php` (Web platform)
- Teams Admin Center meeting policy on the service account:
  - `AllowCloudRecording = true`
  - `AutoRecording = Enabled`
  - `AutoAdmittedUsers = InvitedUsers` (or as appropriate)
- IOMAD: courses must be assigned to a company for IOMAD completion observers to fire correctly

---

## Installation

1. Extract the zip into `{moodle_root}/mod/msteamsecp/`
2. Visit Site Administration → Notifications to run the upgrade
3. Go to Site Administration → Plugins → Activity modules → ECP Teams Meeting
4. Enter your Azure credentials (Tenant ID, Client ID, Client Secret, Service Account UPN)
5. Click **Authorize service account** to complete the OAuth flow
6. Confirm the settings page shows ✅ Connected

> **Upgrading from 1.4.5 or earlier:** Re-enter Client ID and Client Secret after upgrading. Previous versions stored credentials encrypted; 1.4.6+ stores plaintext. The old encrypted values will not authenticate correctly until re-entered.

---

## Plugin Settings

| Setting | Description |
|---|---|
| Tenant ID | Azure AD tenant ID |
| Client ID | App registration client ID (masked in UI, plaintext storage) |
| Client secret | App registration client secret (masked in UI, plaintext storage) |
| Service account UPN | Email of the Teams service account |
| Default lobby bypass | Default lobby bypass setting for new meetings |
| Default attendance threshold % | Minimum attendance % for live attendance completion credit |
| Recording completion threshold % | Watch % required for recording completion credit (default: 80). Overridable per activity via "Minimum watch % required" |

---

## Architecture

```
msteamsecp/
├── amd/
│   ├── src/recording_player.js          # HTML5 video tracker — high-water mark, AJAX completion
│   └── build/recording_player.min.js
├── classes/
│   ├── api/graph.php                    # Graph API client — meetings, calendar, attendance, recordings
│   ├── completion/custom_completion.php # Moodle 4.2+ custom completion class
│   ├── external/
│   │   └── mark_recording_complete.php  # Web service: grant completion at watch threshold
│   ├── sync/
│   │   ├── meeting_creator.php          # Create, update, delete, co-organiser sync
│   │   ├── enrolment_handler.php        # Calendar push on enrol (all vs next); removal on complete
│   │   └── post_event_processor.php     # Attendance, completion, recording pipeline, occurrence advance
│   ├── task/process_events.php          # Scheduled task wrapper (every 15 min)
│   ├── privacy/provider.php             # GDPR Privacy API
│   └── event/course_module_viewed.php
├── db/
│   ├── install.xml                      # 5 tables
│   ├── access.php                       # Capabilities
│   ├── services.php                     # Web service: mark_recording_complete
│   ├── upgrade.php
│   └── tasks.php
├── backup/moodle2/                      # Backup and restore
├── lang/en/msteamsecp.php
├── pix/
│   ├── icon.svg                         # Teams 2025 icon
│   └── monologo.svg                     # Theme fallback icon
├── lib.php                              # Moodle hooks, calendar API callbacks, pluginfile
├── view.php                             # Learner-facing activity page + inline recording player
├── mod_form.php                         # Meeting creation/edit form
├── oauth_authorize.php                  # OAuth flow — redirect to Microsoft login
├── oauth_callback.php                   # OAuth flow — token exchange and storage
├── settings.php                         # Admin settings with masked credential inputs
├── ical.php                             # iCal download endpoint
├── index.php
├── monologo.svg                         # Root-level icon fallback for some themes (e.g. Space)
└── version.php
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `msteamsecp` | One row per activity — Graph IDs, settings, recurrence config, attendance_requirement |
| `msteamsecp_occurrences` | One row per occurrence — status, recording file anchor, attendance state |
| `msteamsecp_attendance` | Per-user per-occurrence — join/leave times, duration %, credit, credit_method |
| `msteamsecp_enrollee_events` | Per-user per-occurrence — Graph calendar event ID, removal state |
| `msteamsecp_coorganisers` | Selected co-organisers per meeting instance |

---

## Scheduled Task

Runs every 15 minutes. For each occurrence past its end time (with a 20-minute grace period for Teams report generation):

1. Mark occurrence as `ended`
2. Fetch Graph attendance reports (all pages) → select the reports matching this occurrence → merge each attendee's intervals → calculate percentage against scheduled duration → store per-user records → grant completion credit
3. If **attend at least one** mode: advance incomplete users to next occurrence (rolling calendar push)
4. Check for completed recording → match it to this occurrence → download → store in Moodle → inline player available
5. Recording watch credit also triggers completion check via AMD player AJAX call

Attendance keeps being re-polled for 6 hours after an occurrence ends to catch late reports. Both attendance and recording retrieval **give up 7 days after an occurrence ends** if nothing ever appears — an occurrence that was never actually held, such as a recurring session skipped for a holiday, produces neither, and without the cut-off it would be re-queried against Graph on every run forever. To make the cron try again for a specific occurrence, set `attendance_fetched = 0` or `recording_abandoned = 0` on its `msteamsecp_occurrences` row. Uploading a recording manually clears `recording_abandoned` automatically.

Next-occurrence invites are only pushed to **actively enrolled** learners — suspended user enrolments, disabled enrolment methods and enrolments that have not started or have expired are all excluded.

To manually trigger for backlogged occurrences:
```bash
php admin/cli/scheduled_task.php --execute=\\mod_msteamsecp\\task\\process_events
```

---

## Known Limitations

- Moodle mobile app not yet supported
- Graph does not allow PATCH on the calendar event time or subject after creation for Teams online meeting-linked events (returns 405). If a meeting time changes significantly, delete and recreate the activity.
- Teams Admin Center meeting policy settings on the service account can override per-meeting API settings. If lobby bypass or recording are not working, verify the policy assigned to the service account.
- The delegated refresh token expires after 90 days. The settings page warns at 75 days. Re-authorization takes ~30 seconds — click **Authorize service account** and sign in as the service account.
- IOMAD: the `local_iomad_observer::course_completed` observer will throw an exception if the course is not assigned to a company. This does not affect Moodle's own completion records — activity and course completion are written correctly before the IOMAD observer fires. Assign all courses to a company in IOMAD to prevent the exception.
- Existing meetings created before 1.4.7 will not have Moodle calendar events. Open and re-save each activity to trigger the calendar sync.
- Users sharing a computer who need manual completion credit: grant completion via Course → Reports → Activity completion → check the box for each affected user. The next cron run will stop sending them further invites.

---

## Version History

| Version | Notes |
|---|---|
| 1.0.0 | Initial release — Graph API integration, recurring meetings, attendance, recording pipeline, calendar push, co-organiser sync |
| 1.1.0 | Privacy API (GDPR), backup/restore, manual co-organiser selection |
| 1.1.1 | Rolling calendar push — enrol pushes next occurrence only; post-attendance processor advances incomplete users |
| 1.2.0 | Co-organiser role sync via Graph PATCH; iCal download; theme compatibility fixes |
| 1.3.0 | Per-meeting lobby bypass; co-organiser labelling; enrollee invites via real Outlook calendar events |
| 1.4.0 | Delegated OAuth token — meeting creation uses Calendar API with user token; co-organisers get full permissions. Refresh token AES-256-CBC encrypted. |
| 1.4.5 | Inline HTML5 recording player with configurable watch threshold completion. Recordings stored under `mod_msteamsecp` filearea. Attendance requirement setting (attend once / attend all). Join button restricted to 15-minute pre-meeting window. |
| 1.4.6 | Credential encryption removed — Client ID and Secret stored plaintext, masked in UI. AWS RDS encryption at rest provides storage-layer protection. Moodle 4.2 compatibility fixes. `monologo.svg` added for theme compatibility. `custom_completion` class replacing deprecated `_get_completion_state()` callback. Bug fixes: recurrence end date off-by-one, recording date format, `$CFG` scope in post_event_processor, settings page token status performance. |
| 1.4.7 | Moodle LMS calendar integration — meeting events appear on course calendar, site calendar, upcoming events block, and dashboard timeline. Calendar action callback provides join link on dashboard, active 15 minutes before start, hidden after completion. Attendance percentage now based on actual meeting duration from Graph report, capped at 100%. Co-organiser field pre-populated on activity edit. `completion_attendance_pct` moved out of `customcompletionrules` to fix coding error. Calendar API callbacks use correct `mod_` prefix and `$userid` parameter. |
| 1.5.0 | **Backup/restore enabled** — `FEATURE_BACKUP_MOODLE2` corrected to `true`; backup files were present but Moodle was not using them. **Activity completion calendar removal** — Outlook/Teams invites now removed immediately when activity completion is granted (via cron, recording watch, or manual admin grant), not only when the full course completes. New `course_module_completion_updated` observer and `on_activity_complete()` method in enrolment handler. **Manual completion support** — next-occurrence invite push and initial enrolment push both check Moodle's `course_modules_completion` table, so manually-granted completion (e.g. users sharing a computer) stops further invites correctly. All `MapleLMS` references replaced with `Moodle` throughout documentation. |
| 1.5.2–1.5.4 | Form submit JS crash fixed (`addRule('required')` on `date_time_selector`). Co-organiser autocomplete switched to AJAX. `get_coursemodule_info()` merged two queries into one. |
| 1.5.5 | Co-organiser selection changed from LMS user autocomplete to a free-text email textarea, eliminating the YUI page-freeze on large IOMAD installs. `msteamsecp_coorganisers` migrated to email-only. |
| 1.5.6 | Bi-weekly occurrence expansion fixed; attend-once LMS calendar filtering added. |
| 1.6.0 | `meeting_creator::update()` now runs the full sync pipeline on edit: PATCH onlineMeeting → rebuild occurrence rows with DST-correct expansion → delete/recreate the service account calendar event (Graph 405 workaround) → resend corrected Outlook invites → sync co-organisers. Fixes errant LMS calendar events on resave and DST-shifted occurrence times. |
| 1.6.1 | Attendance % denominator changed to scheduled duration. Completion switched to OR logic so live attendance alone satisfies completion. Recording player scrubbing prevention fixed. Threshold recalculation on save added — but see 1.7.0, it threw on every save and never actually ran. |
| 1.7.0 | Recording completion reworked around **unique watch time** with per-activity threshold and persistent, resumable progress (`msteamsecp_watch_progress` + `save_watch_progress` web service). Fixed `dml_read_exception` in `msteamsecp_recalculate_attendance_credit()` (selected a non-existent `occ.course`). Added the missing `upload_recording.php`. Backup now includes `lobby_bypass`, `attendance_requirement`, `completion_recording_pct`. |
| 1.7.2 | **Duplicate recurring meetings fixed.** Re-saving a recurring activity appended another full copy of every occurrence that had already started — three saves of a three-session series produced nine, showing as three meetings at the same time each recurring three times. Occurrence rows are now reconciled rather than rebuilt, so saving is idempotent. The "ends on date" recurrence option was removed in favour of an occurrence count (existing meetings are converted automatically). Upgrade de-duplicates existing occurrence rows without touching any that carry attendance data. |
| 1.7.1 | **Live attendance credit fixed.** Recording retrieval and attendance fetching now give up 7 days after an occurrence ends (new `recording_abandoned` column) instead of re-querying Graph forever for a session that was never held. Next-occurrence invites now skip suspended and expired enrolments. The cron previously read a single attendance report per occurrence — `end()` of an unordered, unpaged list — with no correlation to the occurrence's date, then latched `attendance_fetched` permanently. On recurring meetings that routinely processed the wrong occurrence's report, so most attendees were never credited even at a 0% threshold. Reports are now paged in full, matched to the nearest occurrence, and merged per attendee (overlaps unioned); attendance is re-polled for 6 hours to catch late reports and abandoned after 7 days; unresolvable and anonymous attendees are logged instead of silently dropped. Email matching is now case-insensitive and understands Azure guest (`#EXT#`) addresses. Recordings are matched to their occurrence the same way — previously every occurrence in a series downloaded the first recording. Also: completion no longer breaks when an activity uses automatic completion with only "student must view" ticked, and `mark_recording_complete` is no longer exposed as a web service (it trusted a client-supplied watch percentage). |

---

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE) for full terms.

Copyright (C) 2026 Eyecare Partners. Developed for internal use at Eyecare Partners. Distribution outside of Eyecare Partners is not authorized except as required by the terms of the GNU GPL v3.
