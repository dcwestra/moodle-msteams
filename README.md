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

**Self-hosting is possible but adds infrastructure burden:** The plugin does support self-hosting the meetingapp on your own infrastructure, but this adds a separate application to deploy, maintain, and secure — in addition to Moodle itself.

`mod_msteamsecp` eliminates all of these concerns. All communication is directly between your MapleLMS server and Microsoft's Graph API over HTTPS. No third-party service is involved at any point in the meeting lifecycle. Your Azure credentials never leave your infrastructure, and there is no external availability dependency.

---

## Why This Plugin Exists

The community `mod_msteams` plugin works as follows: when an instructor creates a meeting activity, an iframe loads an external third-party web application hosted at `enovation.ie/msteams`. The instructor schedules the meeting inside that iframe, and the resulting Teams join URL is captured into a hidden form field and stored in Moodle as a plain URL — functionally identical to the built-in `mod_url` resource, which it is actually built on top of.

This architecture has significant limitations:

- **External dependency** — meeting creation depends on a third-party service at `enovation.ie` that is outside your control and could change or disappear
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
| Lobby bypass | Manual in Teams after creation | Governed by Teams meeting policy on the service account — co-organisers bypass automatically |
| Auto-record | Manual in Teams after creation | Toggle per meeting in Moodle form |
| Recurring meetings | Not supported | Daily / weekly / monthly with day-of-week selection |
| Co-organiser assignment | Not supported | Manual selection per meeting; presenter rights synced via Graph |
| Enrollee calendar push | Not supported | Next occurrence pushed on enrol; advances automatically until completion |
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
Lobby access is governed entirely by the Teams meeting policy assigned to the service account in the Teams Admin Center — not by any per-meeting setting in the plugin. With the policy set to **"Organizers and co-organizers only"**, co-organisers bypass the lobby automatically by virtue of their presenter role. Learners are held in the lobby until a co-organiser admits them, which is the correct behaviour for a managed training session. Because the service account (the technical organiser) is never present in the meeting, at least one co-organiser must always be assigned — this is enforced by the plugin form.

### Auto-Record
A per-meeting toggle starts recording automatically when the meeting begins. Works in conjunction with the recording retrieval pipeline.

### Recurring Meetings
Full recurrence support with daily, weekly, and monthly patterns. Weekly recurrence supports specific day-of-week selection (e.g. every Monday and Wednesday). Series can end by date or occurrence count. Each occurrence is tracked individually with its own attendance report and recording.

### Recording Behavior (Recurring)
For recurring meetings, recordings can either be appended (one recording activity per occurrence, building an archive) or replaced (the recording activity is updated in place, always showing the most recent session).

### Co-organiser Assignment
At least one co-organiser is required on every meeting — the form will not save without one. Co-organisers are given presenter rights on the Teams meeting via Graph (`PATCH /onlineMeetings`), allowing them to start the meeting, admit participants from the lobby, manage recording, and control the session. Because the Teams meeting policy is set to "Organizers and co-organizers only" for lobby bypass, co-organisers are also the gatekeepers — learners cannot enter until a co-organiser is present. Requires the Application Access Policy to be configured by IT (see IT Admin Setup below).

### Enrollee Calendar Push — Rolling Single-Occurrence Model
When a user enrols in a course containing a Teams meeting activity, the plugin pushes **only the next upcoming occurrence** to their Outlook/Teams calendar — not the entire series. This keeps calendars clean and avoids showing a year's worth of sessions at once.

After each occurrence ends and attendance is processed, the plugin automatically pushes the next occurrence to any enrolled user who has not yet earned completion credit. This continues until the user attends a qualifying session (triggering course completion) or is unenrolled — at which point the calendar event is removed.

**The full lifecycle for a recurring orientation-style event:**
1. Learner enrols → one occurrence appears on their Teams calendar
2. They attend and meet the threshold → credit granted → course complete → calendar event removed
3. They don't attend (or don't meet the threshold) → next occurrence pushed to their calendar automatically
4. Steps 2–3 repeat until they complete or are unenrolled

### Attendance Tracking
After each meeting ends, a scheduled task fetches the attendance report from Graph. Per-user join time, leave time, total duration, and attendance percentage are stored per occurrence. No manual effort required.

### Completion by Attendance
Each meeting has a configurable attendance threshold (0–100%). Setting it to 0 grants credit for any join. Setting it to 75 requires the user to have been present for at least 75% of the meeting duration. Credit is granted automatically — no manual grading.

### Recording Retrieval
After each meeting ends, the scheduled task polls Graph for recording availability. Once ready, the recording is downloaded from OneDrive and stored as a standard Moodle file resource in a plugin-managed "Session Recordings" course section, created automatically if it doesn't exist.

### Recording Upload (Manual Mode)
In manual mode, instructors see an upload button on ended sessions and upload the video file directly into Moodle, stored as a resource activity in the same Session Recordings section.

### Completion by Recording
Recording activities created by the plugin use Moodle's standard completion system. Instructors configure completion conditions (view, mark as done, etc.) through the normal course completion UI.

### Post-Event Processor
A scheduled task runs every 15 minutes handling all post-event automation: marking ended occurrences, fetching attendance reports, processing completion credit, polling for recordings, downloading and storing recording files, creating recording activities, and advancing enrolled users to their next occurrence.

### Learner View
- Upcoming: countdown, join button
- Live: prominent join button with live badge
- Ended: recording link if available, attendance summary with credit status
- Recurring series: table of all occurrences with individual status, join links, and recording links
- New enrollees: access to all past recordings retroactively

---

## Requirements

- Moodle 4.0+ / MapleLMS
- PHP 8.0+
- Microsoft 365 tenant
- Azure app registration with Application permissions (see IT Admin Setup below)
- A licensed Microsoft 365 service account used as the technical meeting organiser
- Teams Application Access Policy configured by a Teams administrator (see IT Admin Setup below)

---

## IT Admin Setup

This section covers everything IT needs to configure before the plugin will function. There are four distinct areas: Azure app registration, the service account, Teams PowerShell policy, and the Teams meeting policy. All four are required.

---

### 1. Azure App Registration

The plugin authenticates to Microsoft Graph using the OAuth 2.0 client credentials flow (app-only, no user sign-in). This requires an app registration in your Azure AD tenant.

#### Create the App Registration

1. Sign in to the [Azure Portal](https://portal.azure.com) as a Global Administrator or Application Administrator
2. Navigate to **Azure Active Directory → App registrations → New registration**
3. Set the following:
   - **Name:** `MapleLMS Teams Integration` (or similar)
   - **Supported account types:** Accounts in this organizational directory only (single tenant)
   - **Redirect URI:** Leave blank — this app uses client credentials, not user sign-in
4. Click **Register**
5. Note the **Application (client) ID** and **Directory (tenant) ID** from the Overview page — these go into the plugin settings

#### Add API Permissions

Navigate to **API permissions → Add a permission → Microsoft Graph → Application permissions** and add all of the following:

| Permission | Purpose |
|---|---|
| `OnlineMeetings.ReadWrite` | Create, update, and delete Teams meetings |
| `Calendars.ReadWrite` | Create and remove calendar events on the service account and user mailboxes |
| `OnlineMeetingRecording.Read.All` | Fetch recording files from OneDrive after meetings end |
| `OnlineMeetingTranscript.Read.All` | Reserved for future transcript support |
| `User.Read.All` | Resolve user UPNs to object IDs; look up co-organiser profiles |

After adding all permissions, click **Grant admin consent for [your tenant]** and confirm. All permissions must show a green "Granted" status — the plugin will fail if any are missing consent.

> **Note:** All of these are **Application permissions**, not Delegated. The plugin runs as a background service with no signed-in user context.

#### Create a Client Secret

1. Navigate to **Certificates & secrets → New client secret**
2. Set a description (e.g. `MapleLMS Plugin`) and an expiry that fits your rotation policy
3. Copy the **Value** immediately — it will not be shown again
4. Store it securely; this goes into the plugin's Client Secret setting in MapleLMS

> **Security note:** Treat the client secret as a privileged credential. It grants application-level access to create meetings, read recordings, and write to user calendars across the entire tenant. Rotate it on a defined schedule and update the plugin setting when you do.

---

### 2. Service Account

The plugin creates all Teams meetings on behalf of a shared service account — this account is the meeting organiser in Teams. It must be a real licensed Microsoft 365 user account, not a resource mailbox or guest.

#### Requirements

- A standard Microsoft 365 user account with a license that includes **Microsoft Teams** and **Exchange Online** (e.g. Microsoft 365 E3/E5, or Teams Essentials + Exchange Online Plan 1)
- The account must be a member of your organisation's Azure AD tenant (not a guest)
- An active Exchange Online mailbox is required so calendar events can be created

#### Recommended Account Configuration

| Setting | Value |
|---|---|
| Display name | `Learning Help` or `MapleLMS Teams` |
| UPN / email | A dedicated account e.g. `mapleLMS@yourcompany.com` |
| License | Microsoft 365 E3 or equivalent (Teams + Exchange required) |
| MFA | Excluded from interactive MFA enforcement (see note below) |
| Password expiry | Set to never expire, or establish a rotation process |

> **MFA note:** The plugin authenticates using the client credentials flow — it never signs in as the service account interactively. The service account's own MFA settings do not affect Graph API calls made by the plugin. However, if your tenant enforces MFA via conditional access for all accounts without exception, ensure the service account is placed in a conditional access exclusion group scoped to non-interactive service principals.

---

### 3. Teams Application Access Policy (PowerShell)

This is the most commonly missed step and the most likely cause of silent failures in production.

By default, even with `OnlineMeetings.ReadWrite` granted and admin-consented, Graph API calls to `PATCH /users/{id}/onlineMeetings/{meetingId}` will return **404** for app-only tokens. This is a Teams-level access control layer that is entirely separate from Azure AD permissions. Microsoft requires an explicit **Application Access Policy** scoped to the service account UPN.

Without this policy, the following plugin features will fail silently:
- Updating meeting settings after initial creation (time changes, lobby toggle changes)
- Assigning presenter rights to co-organisers

#### Prerequisites

The **Microsoft Teams PowerShell module** must be installed on your admin workstation:

```powershell
Install-Module MicrosoftTeams -Force
```

You will need a Teams Administrator or Global Administrator account to run these commands.

#### Create and Grant the Policy

```powershell
Connect-MicrosoftTeams

New-CsApplicationAccessPolicy `
    -Identity "MapleLMS-OnlineMeetings-Policy" `
    -AppIds "<YOUR-CLIENT-ID>" `
    -Description "Allows MapleLMS to manage Teams meetings via Graph API"

Grant-CsApplicationAccessPolicy `
    -PolicyName "MapleLMS-OnlineMeetings-Policy" `
    -Identity "<SERVICE-ACCOUNT-UPN>"
```

> **Propagation delay:** Policy changes in Teams can take up to 30 minutes to replicate across the tenant. Do not assume the policy is broken if PATCH calls still return 404 immediately after running these commands — wait at least 30 minutes before troubleshooting.

#### Verify the Policy

```powershell
Get-CsApplicationAccessPolicy -Identity "MapleLMS-OnlineMeetings-Policy"
Get-CsApplicationAccessPolicy -Identity "<SERVICE-ACCOUNT-UPN>"
```

---

### 4. Teams Meeting Policy — Lobby Bypass

This setting is about a specific operational problem: the service account is the meeting organiser in Teams, but it is never actually present in the meeting. In Teams, if the organiser hasn't joined yet, participants can be held in the lobby with no one to admit them — even if an instructor is present.

The correct policy setting for this plugin is **"Organizers and co-organizers only"**, not "People in my organization." Here's why each group is handled correctly:

- **Service account (organiser)** — bypasses by policy, but is never present. This is fine.
- **Co-organisers (instructors/facilitators)** — bypass by policy and land directly in the meeting, where they can start the session and admit learners.
- **Learners** — held in the lobby until a co-organiser admits them. This is intentional. It ensures a facilitator is always present before the session begins, and it's why the plugin requires at least one co-organiser on every meeting.

> **There is no per-meeting lobby bypass toggle in the plugin.** Lobby behaviour is set once at the Teams policy level and applies consistently to every meeting. This is simpler and more predictable than a per-meeting toggle.

#### Configure in Teams Admin Center

1. Sign in to the [Teams Admin Center](https://admin.teams.microsoft.com)
2. Navigate to **Meetings → Meeting policies**
3. Either edit the policy currently assigned to the service account, or create a new one
4. Under **Meeting join & lobby**, set **Who can bypass the lobby** to **Organizers and co-organizers**
5. Assign the policy to the service account:
   - Go to **Users → Manage users**
   - Find the service account user
   - Under **Policies**, assign the meeting policy you configured

---

### 5. IT Prerequisite Checklist

Use this to confirm everything is in place before handing off to L&D for testing:

| Step | Where | Done |
|---|---|---|
| App registration created in Azure AD | Azure Portal | ☐ |
| All 5 API permissions added (Application type, not Delegated) | Azure Portal | ☐ |
| Admin consent granted for all permissions | Azure Portal | ☐ |
| Client secret created and value stored securely | Azure Portal | ☐ |
| Service account licensed (Teams + Exchange Online) | M365 Admin Center | ☐ |
| Service account excluded from interactive MFA enforcement | Azure AD / Conditional Access | ☐ |
| `New-CsApplicationAccessPolicy` created with correct App ID | Teams PowerShell | ☐ |
| `Grant-CsApplicationAccessPolicy` granted to service account UPN | Teams PowerShell | ☐ |
| Meeting policy with lobby bypass configured | Teams Admin Center | ☐ |
| Meeting policy assigned to service account | Teams Admin Center | ☐ |
| Tenant ID, Client ID, Client Secret, Service Account UPN handed off to L&D | — | ☐ |

---

## Installation

1. Place the `msteamsecp` folder in `/path/to/moodle/mod/`
2. Fix ownership: `chown -R www-data:www-data /path/to/moodle/mod/msteamsecp`
3. Run `php admin/cli/upgrade.php --non-interactive` or visit `/admin/index.php`
4. Configure plugin settings (see Configuration below)

**Note:** The plugin folder must be named `msteamsecp` (no `mod_` prefix). The component name `mod_msteamsecp` is used internally by Moodle.

---

## Configuration

**Site Administration → Plugins → Activity modules → Teams Meeting (ECP)**

| Setting | Description |
|---|---|
| Azure Tenant ID | Your M365 tenant GUID |
| Client ID | Azure app registration client ID |
| Client Secret | Azure app registration client secret |
| Service Account UPN | UPN of the shared organiser account e.g. `mapleLMS@yourcompany.com` |
| Co-organiser roles | Comma-separated Moodle role shortnames. Default: `editingteacher,teacher,manager` |
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
| Lobby bypass | On |
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
│   ├── upgrade.php          # Upgrade path from v1.0 → v1.1 (adds coorganisers table)
│   └── tasks.php            # Scheduled task: post-event processor (every 15 min)
├── classes/
│   ├── api/
│   │   └── graph.php        # Graph API client — meetings, events, attendance, recordings
│   ├── sync/
│   │   ├── meeting_creator.php      # Meeting creation, update, delete, co-organiser sync
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
├── ical.php                 # iCal feed for calendar integration
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
| `msteamsecp_coorganisers` | Manually selected co-organisers per meeting instance |

---

## Known Limitations

- Moodle mobile app not yet supported
- Calendar event attendees (co-organisers on the service account calendar event) cannot be updated via Graph PATCH after creation for Teams online meeting events — attendees are set at creation time only. Presenter rights on the meeting itself can be updated at any time (requires Application Access Policy)
- Graph does not allow PATCH on the calendar event time or subject after creation for Teams online meetings (returns 405). If a meeting time changes significantly, delete and recreate the activity
- Attendance reports require `OnlineMeetingRecording.Read.All` — some tenant configurations may restrict this permission

---

## Version History

| Version | Notes |
|---|---|
| 1.0.0 | Initial release — full Graph API integration, recurring meetings, attendance tracking, recording pipeline, calendar push/removal, co-organiser sync |
| 1.1.0 | Added Privacy API (GDPR data export and anonymised deletion), backup/restore support, upgrade path, manual co-organiser selection per meeting |
| 1.1.1 | Rolling calendar push — enrol pushes next occurrence only; post-attendance processor advances incomplete users one occurrence at a time |
| 1.2.0 | Removed per-meeting lobby bypass toggle — lobby access is now governed by the Teams meeting policy on the service account ("Organizers and co-organizers only"). Co-organiser selection is now required on every meeting; form validation enforces this. Drops `lobby_bypass` database column via upgrade step |

---

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE) for full terms.

Copyright (C) 2026 Eyecare Partners. This software was developed for internal use at Eyecare Partners. Distribution outside of Eyecare Partners is not authorized except as required by the terms of the GNU GPL v3.
