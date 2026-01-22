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
- **Entry Point**: `index.php` serves as the main landing page and build area
- **Configuration**: `config.php` contains MySQL credentials and environment settings
- **Error Handling**: PHP errors displayed (`display_errors = 1`) during development/demo phase

### Data Storage
- **Database**: External MySQL service
- **Key Tables**:
  - Cars and parts tables with junction tables for compatibility
  - User builds with slot-based position data (string identifiers like "engine", "brakes_front")
  - Community posts with one-layer comment threading
  - `user_likes` table for tracking likes per user
  - `user_saved_builds` for bookmarking builds
  - `click_logs` for affiliate tracking
- **Seed Data**: `data.sql` includes Honda Civic, example parts, and default admin account

### Build System
- Users can create multiple builds per car
- Build titles are required fields
- Parts snap into defined slots (not free-form x/y positioning)
- Auto-save functionality for work-in-progress builds
- Fork action creates a clone for editing while preserving original

### Authentication & Authorization
- User accounts with password-protected deletion (requires password re-entry)
- Admin whitelist managed directly in SQL
- Default admin credentials: `admin@modmycar.com` / `password123`

### Affiliate System
- Server-side redirect script (`redirect.php?part_id=...`) logs clicks before redirecting
- URL format: `base_url + link` (concatenated without slash; `parts.link` includes leading slash if needed)
- Revenue display shows click metrics only (no conversion estimates)

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