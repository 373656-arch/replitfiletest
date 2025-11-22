# ModMyCar - Car Modification Platform

## Overview
ModMyCar is a comprehensive web platform for car enthusiasts to design custom builds using a drag-and-drop interface, share their creations with the community, and earn affiliate revenue through part recommendations.

## Project Status
Complete MVP implementation with all features from the PRD:
- ✅ User authentication and profiles
- ✅ Drag-and-drop build system
- ✅ Community sharing with likes and comments
- ✅ Admin panel with Chart.js analytics
- ✅ Affiliate link tracking
- ✅ Dark/Light theme support
- ✅ Fully responsive design

## Recent Changes
- **October 17, 2025**: Initial project implementation
  - Created complete database schema with 12 tables
  - Built user authentication system
  - Implemented drag-and-drop build interface
  - Created community features (likes, comments, forking)
  - Built admin panel with analytics dashboard
  - Added responsive CSS theme with dark/light modes

## Database Setup Required

**IMPORTANT**: This application requires an external MySQL database. Follow these steps:

1. **Create a MySQL Database**
   ```sql
   CREATE DATABASE modmycar;
   ```

2. **Import the Schema**
   ```bash
   mysql -u your_username -p modmycar < data.sql
   ```

3. **Update Database Credentials**
   Edit `config.php` and update the database connection settings:
   ```php
   define('DB_HOST', 'your_host');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'modmycar');
   ```

## Default Admin Account
- **Email**: michaeltest@modmycar.com
- **Password**: admin123

## Project Architecture

### Technology Stack
- **Backend**: PHP 8.2
- **Database**: MySQL
- **Frontend**: Plain JavaScript (no frameworks)
- **Charts**: Chart.js (CDN)
- **Styling**: Custom CSS with CSS variables

### File Structure
```
/
├── config.php                 # Database connection & auth helpers
├── index.php                  # Main build area with drag-and-drop
├── community.php              # Community builds, comments, likes
├── admin.php                  # Admin panel with analytics
├── redirect.php               # Affiliate link tracker
├── data.sql                   # Database schema & seed data
├── includes/
│   └── headerFooter.php       # Shared header/footer module
├── user/
│   ├── login.php              # User login
│   ├── register.php           # User registration
│   ├── logout.php             # Logout handler
│   ├── profile.php            # User dashboard
│   └── editProfile.php        # Profile editor & account deletion
└── theme/
    └── style.css              # Dark/Light theme styles
```

### Database Schema
- **users**: User accounts
- **cars**: Available car models
- **parts**: Mod parts catalog
- **builds**: User-created builds
- **build_parts**: Parts in each build
- **affiliate_sources**: Vendor information
- **part_compatibility**: Car-part matching
- **click_logs**: Affiliate click tracking
- **user_likes**: Build likes
- **user_saved_builds**: Saved/bookmarked builds
- **comments**: Build comments & replies
- **admin_whitelist**: Admin access control

## Key Features

### 1. Build System
- Select car make/model
- Drag-and-drop parts into build area
- Parts snap to category slots (exhaust, intake, etc.)
- Real-time price calculation
- Client-side filtering by category, search, price
- Save as private or share with community

### 2. Community
- Browse shared builds
- Filter by car model and budget
- Like and save builds
- Comment system with one-level threading
- Fork builds to customize
- Affiliate link tracking on part clicks

### 3. Admin Panel
- Analytics dashboard with Chart.js:
  - Line chart: Clicks over time
  - Pie chart: Clicks by category
  - Bar chart: Top clicked parts
- CRUD interfaces for:
  - Cars
  - Parts (with compatibility linking)
  - Affiliate sources

### 4. User Features
- Registration and login
- Profile management
- Build history (created & saved)
- Account deletion with password confirmation
- Profile image support

## Monetization
Affiliate links through click tracking:
- Each part has an affiliate source and link path
- Clicks logged to `click_logs` table
- Admin can view click metrics in dashboard
- Revenue is based on successful purchases (35% commission)

## Theme System
Toggle between dark and light modes:
- Dark Mode: Dark backgrounds with red/orange accents
- Light Mode: Light backgrounds with same accent colors
- Preference saved in localStorage

## Security Features
- Password hashing with bcrypt
- SQL injection prevention via prepared statements
- Admin whitelist system
- Session management
- XSS protection with htmlspecialchars

## User Preferences
None configured yet.
