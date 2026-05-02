# ModMyCar - Replit Agent Guide

## Overview

ModMyCar is a web-based platform for car modification enthusiasts, particularly beginners who want to customize and improve their vehicle's performance. The application allows users to:

- Browse compatible car parts across categories (Exhaust, Intake, Suspension, Wheels, Tires, Brakes)
- Create custom builds using a drag-and-drop interface with slot-based part placement
- Share builds with the community and engage through comments/replies
- Track affiliate link clicks for monetization
- Manage content through an admin panel

The platform is built as a traditional PHP/MySQL web application with vanilla JavaScript for interactivity.

## User Preferences

Preferred communication style: Simple, everyday language.

## System Architecture

### Frontend Architecture
- **Technology**: Plain HTML, CSS, and vanilla JavaScript (no frameworks)
- **Styling**: Custom CSS with CSS variables for theming (dark/light mode support)
- **Interactivity**: Native JavaScript for drag-and-drop functionality and client-side filtering
- **Charts**: Chart.js loaded via CDN for displaying metrics
- **Responsiveness**: Full mobile/tablet support required

### Backend Architecture
- **Technology**: PHP with procedural or simple OOP patterns
- **Entry Point**: `index.php` serves as both the landing page (when no car is selected) and the build area
- **Configuration**: `config.php` contains MySQL credentials and environment settings
- **Error Handling**: PHP errors suppressed in production; logged server-side

### Data Storage
- **Database**: External MySQL service
- **Key Tables**:
  - Cars and parts tables with junction tables for compatibility
  - User builds with slot-based position data (string identifiers like "engine", "brakes_front")
  - Community posts with one-layer comment threading
  - `user_likes` table for tracking likes per user
  - `user_saved_builds` for bookmarking builds
  - `click_logs` for affiliate tracking (all "View" link clicks route through redirect.php)
- **Seed Data**: `data.sql` includes Honda Civic, example parts, and default admin account

### Build System
- Users can create multiple builds per car
- Build titles are required fields; image upload is optional (multipart form)
- Parts snap into defined slots (not free-form x/y positioning)
- Fork action creates a clone for editing while preserving original
- Edit action (from profile) uses `?load_build=<id>` which loads parts into session prefill
- Prefill session key is `$_SESSION['prefill_build']` with keys: `car_id`, `parts`, `build_title`

### Authentication & Authorization
- User accounts with password-protected deletion (requires password re-entry)
- Admin whitelist managed directly in SQL
- Default admin credentials: `admin@modmycar.com` / `password123`

### Affiliate System
- Server-side redirect script (`redirect.php?part_id=...`) logs clicks before redirecting
- ALL part "View" links go through `redirect.php` to ensure reliable click tracking
- URL format: `base_url + link` (concatenated without slash; `parts.link` includes leading slash if needed)
- Revenue display shows click metrics only (no conversion estimates)

### Image Upload
- Build images uploaded via save modal (optional file input)
- Files stored in `/uploads/` directory
- MIME type validated server-side before saving
- Path stored in `builds.featured_image` column

## Bug Fixes Applied (May 2026)

1. **Fork/Edit build state** — Parts now correctly carry over when forking or editing. Fixed session prefill car_id being overwritten by GET param. JS now reads `PREFILL_PARTS` and populates the build on load.
2. **Edit button** — Added `?load_build=<id>` handler in index.php that fetches build + parts from DB, sets prefill session, and redirects cleanly.
3. **Click tracking** — All part "View" buttons now route through `/redirect.php?part_id=X` instead of directly to the affiliate URL.
4. **Share to Community** — Added "Share with Community" checkbox to the save build modal (was missing from UI, backend already supported it).
5. **Build image upload** — Users can now upload a build image when saving. Multipart form, MIME-validated, stored in `/uploads/`.
6. **Loading screen on back navigation** — Removed the `beforeunload` event that caused the loading spinner to appear when pressing the browser back button.
7. **Landing page** — index.php now shows a proper hero section, feature highlights, and trending community builds when no car is selected.
8. **Fork prefill link data** — Fork handler in community.php now includes the `link` field when building prefill parts array, so links work correctly in forked builds.

## External Dependencies

### Database
- **MySQL**: External hosted MySQL service for persistent multi-user data storage

### CDN Libraries
- **Chart.js**: Lightweight charting library for displaying analytics and metrics

### Affiliate Networks
- External affiliate URLs stored in parts table
- Click tracking implemented via server-side redirect

### No Additional Dependencies
- No frontend frameworks (React, Vue, etc.)
- No package managers required
- No build tools needed
- All functionality implemented with native PHP/JS
