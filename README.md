# GS MachMit!Haus Signage Plugin

Digital signage system for Kirby 5 with screen management, content channels, block-based content, central device onboarding, and optional whitelist-based access control.

## Features

- 📺 **Screen Management** - Create and manage multiple digital signage screens
- 📡 **Content Channels** - Organize content into reusable channels
- 🎨 **Block-Based Content** - Leverage existing Kirby blocks (headings, text, images)
- 🎬 **Video Support** - Upload videos or embed from YouTube/Vimeo
- 📅 **Calendar Integration** - Display events from iCal/ICS or Kirby pages
- ⏰ **Time-Based Scheduling** - Automatically switch channels based on time
- 🔐 **Access Control** - Optional whitelist-based device approval system
- 🚪 **Onboarding Landing Page** - Central device entry point at `/signage`
- 🧭 **Device Management** - Approve, deny, move, rename, and revoke devices in one place
- 📱 **Orientation Support** - Horizontal and vertical screen layouts
- 🌙 **Standby Mode** - Customizable display during inactive hours
- ⚡ **Auto Duration** - Smart content duration based on text length and media type

## Installation

The plugin is located at:
```
/site/plugins/gs-mmh-signage/
```

It's already installed and ready to use. Just access the panel to start configuration.

## Quick Start

### 1. Access the Signage Panel

1. Log into your Kirby panel
2. Look for **"Signage"** in the sidebar menu (monitor icon)
3. The Signage root page opens with three tabs:
   - **Bildschirme** - Manage display screens
   - **Kanäle** - Manage content channels
   - **Geräteverwaltung** - Manage onboarding and approved devices

### 2. Create Your First Channel

1. Go to **Signage → Channels**
2. Click **"Add"** and create a channel (e.g., "Main Content")
3. Add slides to your channel:
   - **Blocks** - Text, images, headings (uses your existing design system)
   - **Video** - Upload MP4 or embed YouTube/Vimeo
   - **Calendar** - Connect external iCal/ICS feed or use Kirby pages

### 3. Create a Screen

1. Go to **Signage → Screens**
2. Click **"Add"** and configure:
   - **Screen Name**: e.g., "Lobby Display"
   - **URL Slug**: e.g., "lobby-screen"
   - **Orientation**: Horizontal or Vertical
   - **Assigned Channel**: Select your channel
   - **Active Hours**: Set when the screen should display content
   - **Access Control**: Enable whitelist for device approval or disable it for public access
   - **Page Status**: Set the screen to `listed` if it should be reachable as an active screen

### 4. Access Your Screen

Your screen is available at:
```
https://your-domain.com/signage/lobby-screen
```

If you use restricted screens, the central onboarding entry point is:
```
https://your-domain.com/signage
```

## Access Control Workflow

The plugin supports two access modes:

- **Public Mode** - Whitelist disabled, screen is available immediately
- **Restricted Mode** - Whitelist enabled, devices must be approved first

When restricted mode is active, the plugin uses a **request-then-approve** system.

### Device Access Process

1. **First Visit**: Device visits `/signage` or the screen URL
2. **UUID Generated**: Browser creates a unique device ID (stored in localStorage)
3. **Access Request**: System records the request with:
   - Device UUID
   - IP Address
   - Backend / URL metadata from onboarding
   - User Agent (device info)
   - Request timestamp
4. **Pending State**: Screen displays "Access Pending" message
5. **Admin Approval**: Editor reviews pending requests in **Geräteverwaltung**
6. **Whitelist Added**: Upon approval, device is added to whitelist
7. **Auto-Redirect**: Device is sent to the assigned screen automatically

### Managing Access

**In the Signage Root Page:**
- **Geräteverwaltung** is the central place for approvals and denials
- **Approved Devices** can be renamed, reassigned, or revoked
- **Denied Requests** can be deleted or approved later
- **Pending Requests** can be assigned directly to any available screen

**To Approve a Device:**
1. Open **Signage → Geräteverwaltung**
2. Select a screen from the dropdown for the pending device
3. Assign the device
4. Optionally rename it later

**Important:**
- If a device approval is revoked, the device returns to the landing page
- If a device is moved to another monitor, the old monitor redirects it back to `/signage`
- If a screen is deleted, assigned devices are moved back to pending requests
- Public screens bypass the onboarding and approval flow completely

## Duration Calculation

Content duration is calculated automatically when `duration_mode` is set to "Auto":

### Calculation Rules

**Text Content**:
- ~15 characters per second (slow, comfortable reading)
- Minimum: 5 seconds
- Maximum: 120 seconds

**Images**:
- Base: 8 seconds (image comprehension)
- + Caption/alt text reading time
- Maximum: 60 seconds

**Headings**:
- Fixed: 3 seconds

**Lists**:
- Base: 3 seconds
- + 2 seconds per item

**Videos**:
- Upload: Detected from file metadata (requires FFprobe/getID3)
- Embed: Default 30 seconds
- Fallback: 30 seconds

**Calendar**:
- Base: 30 seconds
- + 3 seconds per event (max 10 events)
- Maximum: 120 seconds

### Manual Override

Set `duration_mode` to "Manual" and specify custom duration (5-300 seconds).

The frontend currently respects the configured slide duration for:

- vertical scroll animation
- grid carousel animation

## Time-Based Channel Switching

Screens can automatically switch channels based on time:

### Configuration

In the Screen blueprint, use the **"Time-Based Channel Schedule"** structure:

**Example:**
```
Morning Content (08:00 - 12:00) → Channel: "Morning Announcements"
Afternoon Content (12:00 - 17:00) → Channel: "Events & Activities"
Evening Content (17:00 - 20:00) → Channel: "Evening Wrap-up"
```

### Conflict Prevention

The system **prevents overlapping time ranges**:
- Validation hook checks for conflicts on save
- If times overlap, an error is thrown
- Only one channel can be active at any time

### Fallback

If no schedule matches the current time, the screen uses the **"Primary Channel"** (assigned_channel field).

## Standby Mode

When outside active hours, screens display a standby state:

### Standby Options

1. **Blank Screen** - Solid black
2. **Logo Only** - Show the MMH logo on a branded standby background
3. **Custom** - Image + custom message

### Configuration

In Screen blueprint:
- **Standby Mode**: Select display type
- **Standby Image**: Upload background image
- **Standby Message**: Custom text (e.g., "Display resumes at 8:00 AM")
- **Transition Duration**: Fade-in time (0-60 seconds)

## Content Types

### 1. Block Content (Recommended)

Uses your existing Kirby block system:

**Available Blocks:**
- **Heading** - H1-H6 with design system styles
- **Text** - Rich text with KirbyText support
- **Image** - Images with optional captions
- **List** - Bulleted or numbered lists

**Best For:**
- Announcements
- Text-heavy slides
- Mixed content (text + images)

### 2. Video

**Upload (Local)**:
- Supports MP4, WebM, OGG
- Autoplay, mute, loop options
- Duration detected automatically (if FFprobe/getID3 available)

**Embed (External)**:
- YouTube, Vimeo, or other embeddable video URLs
- Paste full URL (e.g., `https://youtube.com/watch?v=...`)

**Best For:**
- Promotional videos
- Event recordings
- Tutorial content

### 3. Calendar

**External Source (CalDAV/iCal)**:
- Provide public calendar URL
- System fetches events periodically
- Supports .ics format

**Kirby Pages**:
- Select event pages from your site
- Display information from page fields
- Requires pages with `date` field

**Layouts:**
- **List** - Simple event list
- **Grid** - Card-based grid with carousel paging
- **Agenda** - Agenda-style grouped list with vertical scroll

**Best For:**
- Event schedules
- Meeting room calendars
- Upcoming activities

## Frontend Player

The player runs client-side JavaScript with:

### Features

- **Auto-Rotation** - Slides advance based on calculated duration
- **Smooth Transitions** - Fade, slide (left/right/up/down)
- **Responsive Layout** - Adapts to screen orientation
- **Access Control** - UUID-based device identification for restricted screens
- **Content State Sync** - Content reloads only when the panel content revision changes
- **Automatic Redirects** - Devices return to onboarding when approval changes
- **Offline Fallback** - Graceful error handling

### Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- ES6+ JavaScript required
- LocalStorage required for restricted screens
- No IE11 support

### Performance

- Preloading: Current slide only (memory efficient)
- CSS transitions: GPU-accelerated
- Video: Native HTML5 player
- Images: Lazy-loaded via browser
- Access changes: Polling-based, no dedicated WebSocket server at the moment

## File Structure

```
site/plugins/gs-mmh-signage/
├── index.php                    # Plugin registration
├── README.md                    # This file
├── blueprints/
│   └── pages/
│       ├── signage.yml          # Root signage page blueprint
│       ├── screens.yml          # Screens container blueprint
│       ├── channels.yml         # Channels container blueprint
│       ├── screen.yml           # Screen blueprint
│       ├── channel.yml          # Channel blueprint
│       └── slide.yml            # Slide blueprint
├── classes/
│   ├── DurationCalculator.php   # Auto-duration calculation
│   ├── ICalParser.php           # External calendar parsing
│   └── AccessController.php     # Access control, onboarding & content delivery
├── fields/
│   ├── onboarding_requests/     # Device management field
│   └── pending_requests/        # Screen device overview field
├── templates/
│   ├── onboarding.php           # Frontend onboarding / landing page
│   └── screen.php               # Frontend player template
├── assets/
│   └── css/
│       └── signage-player.css   # Player styling
└── index.js                     # Panel UI for device management
```

## API Endpoints

The plugin exposes API routes:

### Check Access

```
POST /api/signage/check-access
Body: { screen: "screen-slug", uuid: "device-uuid" }
Response: { status: "success", access: "granted|pending|denied" }
```

### Get Content

```
GET /api/signage/content/{screen-slug}
Response: { status: "active", slides: [...], channel: {...} }
```

### Content State

```
GET /api/signage/content-state/{screen-slug}
Response: { status: "active|standby", revision: "..." }
```

### Onboarding Request

```
POST /api/signage/onboarding/request
Body: { uuid: "device-uuid", backend: "...", url: "..." }
Response: { status: "success|pending|error", access: "granted|pending|denied" }
```

### Onboarding Status

```
GET /api/signage/onboarding-status/{uuid}
Response: { status: "success|pending", access: "granted|pending|denied", screen?: "screen-slug" }
```

### Approve Onboarding Request (Authenticated)

```
POST /api/signage/approve-onboarding-request
Body: { screen: "screen-slug", uuid: "device-uuid", label: "Device Name" }
Response: { status: "success", message: "..." }
```

Additional authenticated routes:

```text
POST /api/signage/deny-onboarding-request
POST /api/signage/remove-denied-onboarding-request
POST /api/signage/revoke-approved-device
POST /api/signage/reassign-approved-device
POST /api/signage/rename-approved-device
```

## Page Methods

Custom page methods available:

### For Slides

```php
$slide->calculatedDuration()           // int - Duration in seconds
$slide->durationCalculationDetails()   // string - Explanation of calculation
```

### For Screens

```php
$screen->isActiveNow()     // bool - Is screen within active hours?
$screen->activeChannel()   // Page|null - Currently active channel
```

## Troubleshooting

### Panel doesn't show Signage menu

**Check:**
- Plugin is in `/site/plugins/gs-mmh-signage/`
- `index.php` exists and has no syntax errors
- Clear panel cache (F5 hard refresh)

### Screen shows "Access Pending" forever

**Fix:**
1. Open **Signage → Geräteverwaltung**
2. Check whether the device is still pending or denied
3. Assign it to a screen
4. Make sure the target screen is `listed`
5. Wait for the automatic redirect or reload the device once if needed

### Screen shows "Screen is inactive"

**Fix:**
1. Open the screen in the panel
2. Set the Kirby page status to `listed`
3. Save the page

The custom active settings do not replace the required Kirby page status.

### Video duration not detected

**Install:**
- FFprobe (via FFmpeg): `brew install ffmpeg` (macOS) or `apt install ffmpeg` (Linux)
- Or: getID3 library via Composer

**Alternative:**
- Use manual duration override in slide settings

### Slides not rotating

**Check:**
- Channel has slides
- Slides have valid content
- Browser console for JavaScript errors
- Screen is within active hours

### Calendar not loading

**Verify:**
- External calendar URL is publicly accessible
- URL returns valid .ics / iCal data
- The chosen range and max events include the expected events

**Alternative:**
- Use Kirby pages instead of external calendar

**Important:**
- Plain HTML pages such as `https://oveda.de/eventdates` are not valid ICS sources
- The plugin expects a real calendar feed URL for external calendars

## Best Practices

### Content

- **Keep text concise** - Under 200 words per slide
- **Use high-quality images** - 1920×1080 recommended
- **Optimize videos** - MP4 H.264, under 50MB
- **Test on actual hardware** - Different devices may render differently

### Scheduling

- **Avoid overlapping time ranges** - System prevents this, but plan ahead
- **Set realistic active hours** - Don't run 24/7 unless necessary
- **Use standby wisely** - Prevents screen burn-in

### Access Control

- **Enable whitelist** for restricted screens
- **Use public mode** for freely accessible screens
- **Label devices clearly** - "Lobby Tablet", "Reception Display"
- **Review pending and denied requests regularly**
- **Use Geräteverwaltung** as the single source of truth for device management

### Performance

- **Limit slides per channel** - 10-20 for optimal rotation
- **Use moderate transition durations** - 1-2 seconds
- **Avoid very long durations** - Max 60-90 seconds per slide
- **Test network connection** - Ensure stable for video playback

## Future Enhancements

Potential additions (not yet implemented):

- **Analytics** - View counts, duration tracking
- **Remote control** - Skip slides, refresh from panel
- **Multi-screen preview** - Preview all screens in panel
- **Content scheduling** - Date ranges for seasonal content
- **Emergency override** - Push urgent messages to all screens
- **Weather widget** - Display current weather
- **RSS feeds** - Display news or blog posts
- **Social media** - Embed Instagram/Twitter feeds

## Support

For issues or questions:

- **Documentation**: This README
- **Project**: GS MachMit!Haus Website
- **Developer**: stuffdev.de

## License

Proprietary - Part of GS MachMit!Haus Web project

---

**Version**: 1.0.0
**Kirby**: 5.x
**PHP**: 8.4+
**Last Updated**: 2026-03-31
