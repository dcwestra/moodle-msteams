<?php
/**
 * English language strings for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

// Plugin.
$string['pluginname']          = 'Teams Meeting (ECP)';
$string['modulename']          = 'Teams Meeting';
$string['modulenameplural']    = 'Teams Meetings';
$string['pluginadministration'] = 'Teams Meeting (ECP) Administration';

// Form.
$string['meetingname']          = 'Meeting title';
$string['timing']               = 'Timing';
$string['starttime']            = 'Start time';
$string['endtime']              = 'End time';
$string['meeting_settings']     = 'Meeting settings';
$string['lobby_bypass']         = 'Who can bypass the lobby';
$string['lobby_bypass_help']    = 'Controls which participants join the meeting directly without waiting in the lobby. The default "Organizers and co-organizers" means facilitators (who are assigned the co-organiser role) bypass the lobby, while learners wait to be admitted.';
$string['lobby_bypass_coorganizers']               = 'Organizers and co-organizers';
$string['lobby_bypass_organization']               = 'People in my organization and guests';
$string['lobby_bypass_organization_no_guests']     = 'People in my organization (excluding guests)';
$string['lobby_bypass_organization_and_federated'] = 'People in my organization, trusted organizations, and guests';
$string['lobby_bypass_invited']                    = 'People I invite';
$string['lobby_bypass_everyone']                   = 'Everyone';
$string['auto_record']          = 'Record automatically';
$string['auto_record_help']     = 'Start recording as soon as the meeting begins.';
$string['recording_mode']       = 'Recording mode';
$string['recording_mode_manual'] = 'Manual — organiser uploads recording after the event';
$string['recording_mode_auto']  = 'Automatic — download from Teams after the event ends';

// Recurrence.
$string['recurrence']           = 'Recurrence';
$string['is_recurring']         = 'This is a recurring meeting';
$string['recurrence_type']      = 'Repeat';
$string['recurrence_daily']     = 'Daily';
$string['recurrence_weekly']    = 'Weekly';
$string['recurrence_monthly']   = 'Monthly';
$string['recurrence_interval']  = 'Every';
$string['recurrence_days']      = 'On these days';
$string['recurrence_end_type']  = 'Ends';
$string['recurrence_end_date']  = 'On date';
$string['recurrence_end_count'] = 'After N occurrences';
$string['recurrence_count']     = 'Number of occurrences';
$string['recording_behavior']   = 'Recording behavior (recurring)';
$string['recording_behavior_append']  = 'Keep all recordings (one per occurrence)';
$string['recording_behavior_replace'] = 'Replace with latest recording';

// Completion.
$string['completion_settings']       = 'Completion';
$string['completion_attendance']     = 'Mark complete when user attends live';
$string['completion_attendance_pct'] = 'Minimum attendance % required (0 = any join)';
$string['completion_attendance_pct_help'] = 'Set to 0 to grant credit for joining at any point. Set to e.g. 75 to require the user attend at least 75% of the meeting duration.';
$string['completion_recording']      = 'Allow recording watch to grant completion credit';
$string['completion_recording_help'] = 'When enabled, watching the recording activity created in the Session Recordings section will count toward course completion.';

// View.
$string['occurrences']          = 'Meeting sessions';
$string['occurrence_date']      = 'Date & time';
$string['status']               = 'Status';
$string['join']                 = 'Join';
$string['recording']            = 'Recording';
$string['attendance']           = 'Attendance';
$string['join_meeting']         = 'Join meeting';
$string['join_now']             = 'Join now';
$string['live_now']             = 'LIVE NOW';
$string['meeting_ended']        = 'Ended';
$string['watch_recording']      = 'Watch recording';
$string['watch']                = 'Watch';
$string['upload']               = 'Upload';
$string['upload_recording']     = 'Upload recording';
$string['starts_in']            = 'Starts in {$a}';
$string['no_occurrences']       = 'No sessions found for this meeting.';
$string['nomeetings']           = 'There are no Teams meetings in this course.';
$string['recording_pending']    = 'Recording will be available here once uploaded.';
$string['your_attendance']      = 'Your attendance';
$string['attendance_credit_granted'] = 'Attendance credit granted — this activity is marked complete.';
$string['attendance_no_credit']      = 'You attended {$a}% of the meeting. No credit threshold has been met yet.';

// Status badges.
$string['status_upcoming']  = 'Upcoming';
$string['status_live']      = 'Live';
$string['status_ended']     = 'Ended';
$string['status_cancelled'] = 'Cancelled';

// Admin settings.
$string['settings_graph_heading']           = 'Microsoft Graph / Azure AD';
$string['settings_tenant_id']              = 'Azure Tenant ID';
$string['settings_tenant_id_desc']         = 'Your Microsoft 365 tenant ID (GUID).';
$string['settings_client_id']              = 'Client ID';
$string['settings_client_id_desc']         = 'Azure app registration client ID.';
$string['settings_client_secret']          = 'Client secret';
$string['settings_client_secret_desc']     = 'Azure app registration client secret.';
$string['settings_service_account_upn']    = 'Service account UPN';
$string['settings_service_account_upn_desc'] = 'UPN of the shared account used to create all meetings e.g. mapleLMS@yourcompany.com';
$string['settings_roles_heading']          = 'Role mapping';
$string['settings_coorganiser_roles']      = 'Facilitator roles';
$string['settings_coorganiser_roles_desc'] = 'Comma-separated list of Moodle role shortnames whose holders are eligible to be selected as facilitators. Default: editingteacher,teacher,manager';
$string['settings_defaults_heading']       = 'Defaults for new meetings';
$string['settings_default_lobby_bypass']      = 'Default lobby bypass';
$string['settings_default_lobby_bypass_desc'] = 'Default "Who can bypass the lobby" setting for new meetings. "Organizers and co-organizers" is recommended — facilitators are assigned the co-organiser role and bypass the lobby automatically.';
$string['settings_default_auto_record']    = 'Auto-record by default';
$string['settings_default_auto_record_desc'] = 'New meetings will record automatically unless changed per meeting.';
$string['settings_default_recording_mode'] = 'Default recording mode';
$string['settings_default_recording_mode_desc'] = 'Whether new meetings default to manual or automatic recording retrieval.';
$string['settings_default_attendance_pct'] = 'Default attendance threshold %';
$string['settings_default_attendance_pct_desc'] = 'Default minimum attendance percentage for completion credit. 0 = any join counts.';
$string['settings_recording_section_name'] = 'Recordings section name';
$string['settings_recording_section_name_desc'] = 'Name of the course section automatically created to hold recording activities. Default: Session Recordings';

// Errors.
$string['error_graph_not_configured'] = 'Microsoft Graph credentials are not configured. Please ask your site administrator to configure mod_msteamsecp settings.';
$string['error_graph_auth']           = 'Microsoft Graph authentication failed: {$a}';
$string['error_graph_request']        = 'Microsoft Graph request failed: {$a}';
$string['error_graph_response']       = 'Unexpected response from Microsoft Graph: {$a}';
$string['error_graph_method']         = 'Unsupported HTTP method: {$a}';
$string['error_recording_download']   = 'Recording download failed (HTTP {$a}).';
$string['error_endtime_before_start'] = 'End time must be after start time.';
$string['error_recurrence_count']     = 'Please enter a valid number of occurrences (minimum 1).';
$string['error_coorganiser_required'] = 'At least one facilitator must be selected. Without a facilitator, learners will be held in the lobby with no one to admit them.';

// Task.
$string['task_process_events'] = 'Teams Meeting ECP — Post-event processor (attendance & recordings)';
$string['event_course_module_viewed'] = 'Teams meeting viewed';

// Privacy API.
$string['privacy:metadata:attendance']                    = 'Attendance records for Teams meeting sessions.';
$string['privacy:metadata:attendance:userid']             = 'The ID of the user who attended.';
$string['privacy:metadata:attendance:join_time']          = 'The time the user first joined the meeting.';
$string['privacy:metadata:attendance:leave_time']         = 'The time the user last left the meeting.';
$string['privacy:metadata:attendance:duration_seconds']   = 'Total seconds the user was present in the meeting.';
$string['privacy:metadata:attendance:attendance_pct']     = 'Percentage of the meeting duration attended.';
$string['privacy:metadata:attendance:credit_granted']     = 'Whether completion credit was granted.';
$string['privacy:metadata:attendance:credit_method']      = 'How credit was granted: live, recording, or none.';
$string['privacy:metadata:enrollee_events']               = 'Calendar events pushed to the user\'s Outlook calendar.';
$string['privacy:metadata:enrollee_events:userid']        = 'The ID of the user whose calendar was updated.';
$string['privacy:metadata:enrollee_events:graph_event_id'] = 'The Microsoft Graph event ID on the user\'s calendar.';
$string['privacy:metadata:enrollee_events:timepushed']    = 'When the calendar event was pushed.';
$string['privacy:metadata:graph']                         = 'This plugin sends data to Microsoft Graph API to create calendar events in users\' Outlook calendars.';
$string['privacy:metadata:graph:email']                   = 'The user\'s email address is used to identify their Microsoft 365 account.';
$string['privacy:metadata:graph:calendar']                = 'Meeting calendar events are created in the user\'s Outlook/Teams calendar.';
$string['privacy:export:attendance']                      = 'Attendance Records';
$string['privacy:export:calendar_events']                 = 'Calendar Events';

// Facilitators.
$string['coorganisers']      = 'Facilitators';
$string['coorganisers_help'] = 'Select at least one facilitator for this meeting. Facilitators receive a calendar invite and can bypass the lobby, start the meeting, admit participants, manage recording, and control the session. Without a facilitator, learners will be held in the lobby with no one to admit them.';
$string['coorganisers_none'] = 'No facilitators selected';

$string['add_to_calendar'] = 'Add to calendar (.ics)';
