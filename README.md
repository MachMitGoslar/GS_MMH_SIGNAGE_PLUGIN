# GS MachMit!Haus Signage Plugin

Digital signage system for Kirby 5 with screen management, content channels, block-based content, and whitelist-based access control.

## Features

- 📺 **Screen Management** - Create and manage multiple digital signage screens
- 📡 **Content Channels** - Organize content into reusable channels
- 🎨 **Block-Based Content** - Leverage existing Kirby blocks (headings, text, images)
- 🎬 **Video Support** - Upload videos or embed from YouTube/Vimeo
- 📅 **Calendar Integration** - Display events from CalDAV/iCal or Kirby pages
- ⏰ **Time-Based Scheduling** - Automatically switch channels based on time
- 🔐 **Access Control** - Whitelist-based device approval system
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
3. You'll see two sections:
   - **Screens** - Manage display screens
   - **Channels** - Manage content channels

### 2. Create Your First Channel

1. Go to **Signage → Channels**
2. Click **"Add"** and create a channel (e.g., "Main Content")
3. Add slides to your channel:
   - **Blocks** - Text, images, headings (uses your existing design system)
   - **Video** - Upload MP4 or embed YouTube/Vimeo
   - **Calendar** - Connect external calendar or use Kirby pages

### 3. Create a Screen

1. Go to **Signage → Screens**
2. Click **"Add"** and configure:
   - **Screen Name**: e.g., "Lobby Display"
   - **URL Slug**: e.g., "lobby-screen"
   - **Orientation**: Horizontal or Vertical
   - **Assigned Channel**: Select your channel
   - **Active Hours**: Set when the screen should display content
   - **Access Control**: Enable whitelist for device approval

### 4. Access Your Screen

Your screen is available at:
```
https://your-domain.com/signage/lobby-screen
```

## Access Control Workflow

The plugin uses a **request-then-approve** whitelist system:

### Device Access Process

1. **First Visit**: Device visits the screen URL
2. **UUID Generated**: Browser creates a unique device ID (stored in localStorage)
3. **Access Request**: System records the request with:
   - Device UUID
   - IP Address
   - User Agent (device info)
   - Request timestamp
4. **Pending State**: Screen displays "Access Pending" message
5. **Admin Approval**: Admin reviews pending requests in panel
6. **Whitelist Added**: Upon approval, device is added to whitelist
7. **Auto-Reload**: Screen automatically loads content (checks every 5 seconds)

### Managing Access

**In the Screen Blueprint:**
- **Access Mode Toggle**: Switch between Public and Whitelist Only
- **Approved Devices**: View and manage whitelisted devices
- **Pending Requests**: Review and approve new device requests

**To Approve a Device:**
1. Open the screen in panel
2. Scroll to "Pending Access Requests"
3. Note the UUID and IP
4. Add to "Approved Devices" structure
5. Provide a friendly label (e.g., "Lobby Tablet")
6. Save the screen

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
2. **Logo Only** - Show a logo image
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
- **Grid** - Calendar grid view
- **Agenda** - Agenda-style view

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
- **Access Control** - UUID-based device identification
- **Auto-Refresh** - Content updates every 60 seconds
- **Offline Fallback** - Graceful error handling

### Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- ES6+ JavaScript required
- LocalStorage required for UUID persistence
- No IE11 support

### Performance

- Preloading: Current slide only (memory efficient)
- CSS transitions: GPU-accelerated
- Video: Native HTML5 player
- Images: Lazy-loaded via browser

## File Structure

```
site/plugins/gs-mmh-signage/
├── index.php                    # Plugin registration
├── README.md                    # This file
├── blueprints/
│   └── pages/
│       ├── signage.yml          # Root signage page blueprint
│       ├── screen.yml           # Screen blueprint
│       ├── channel.yml          # Channel blueprint
│       └── slide.yml            # Slide blueprint
├── classes/
│   ├── DurationCalculator.php   # Auto-duration calculation
│   └── AccessController.php     # Access control & content delivery
├── templates/
│   └── screen.php               # Frontend player template
└── snippets/
    ├── player.php               # Player snippet (future)
    ├── standby.php              # Standby screen snippet (future)
    ├── slide-blocks.php         # Block rendering (future)
    ├── slide-video.php          # Video rendering (future)
    └── slide-calendar.php       # Calendar rendering (future)
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

### Approve Request (Authenticated)

```
POST /api/signage/approve-request
Body: { screen: "screen-slug", uuid: "device-uuid", label: "Device Name" }
Response: { status: "success", message: "..." }
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
1. Check screen blueprint → "Pending Access Requests"
2. Find the device UUID
3. Add to "Approved Devices" structure
4. Save screen
5. Device will auto-reload in ~5 seconds

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
- URL returns valid .ics format
- CORS is enabled on calendar server (for client-side fetching)

**Alternative:**
- Use Kirby pages instead of external calendar

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

- **Enable whitelist** for public spaces (prevents unauthorized access)
- **Use public mode** for internal/trusted networks
- **Label devices clearly** - "Lobby Tablet", "Reception Display"
- **Review pending requests regularly**

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
**Last Updated**: 2025-01-09
