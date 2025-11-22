# Product Requirements Document

This document serves as the complete, final specification, combining all original requirements and technical lock-in decisions. This PRD is sufficient for coding to begin.

## 1\. Executive Decisions & Technical Lock-In

The following decisions lock in the architecture based on the Principal Engineer's questions:

| Requirement | Decision | Rationale |
| :---- | :---- | :---- |
| Database Connection | External MySQL service. Credentials in a new file: config.php. | Assumes a hosted environment necessary for persistent, multi-user data. |
| Frontend Libraries (Drag-and-Drop) | Plain JavaScript. | Simplicity and reduced external dependencies. |
| Charting Library | Chart.js (loaded via CDN). | Lightweight, high-quality, and easy to integrate with dynamic PHP/SQL data. |
| Drag-and-Drop Snap | Parts must snap into defined slots (e.g., engine\_slot). | Enforces a clean, structured build area and simplifies compatibility logic. |
| position\_data Detail | Slot identifiers (e.g., "engine", "brakes\_front"). | Robust and simple string/enum data, avoiding complex x/y pixel data. |
| Fork Build Logic | Immediately load the build, relying on the existing WIP auto-save logic for the currently open build. | Prioritizes user experience by reducing friction. |
| Filtering System | Filter instantly with JavaScript (client-side). | Provides a modern, responsive user experience for browsing parts. |
| Responsiveness | Full mobile/tablet support is required. | Mandatory for a modern web application. |
| Admin Whitelist Editing | Directly in SQL for simplicity. | Avoids unnecessary CRUD complexity in the admin panel for the MVP. |
| Account Deletion | Users must confirm with password re-entry. | Standard security practice for permanent, destructive actions. |
| Multiple Builds | Yes, a user can have multiple builds for the same car. | Allows for experimentation and comparison. |
| Build Titles | Required input field. | Ensures community builds are well-organized and searchable. |
| Click Tracking | Server-side redirect script (redirect.php?part\_id=...). | Most reliable method to log the user/IP before navigating away from the site. |
| Comment Threading | Just one reply layer (top-level comment \+ one reply layer). | Simplifies database queries and UI rendering complexity. |
| Likes | Tracked per user (requires a new user\_likes table). | Prevents spamming and provides accurate popularity metrics. |
| Saved Builds | user\_saved\_builds links to the original build. The "Fork Build" action creates a clone in the user's own builds table for editing. | Maintains the original build's integrity while allowing user customization. |
| Revenue Display | Show click metrics only. | We cannot estimate revenue without conversion data. |
| Affiliate URL Format | Concatenated without a slash. Format: base\_url \+ link. | The parts.link column must include the leading slash if needed. |
| Initial Data | Seed data.sql with Honda Civic, example parts, and the admin user. | Essential for immediate testing. |
| Admin Login | Pre-loaded default admin account: admin@modmycar.com / password123. | For testing and demonstration. |
| Error Display | Show PHP errors (using ini\_set('display\_errors', 1)) for the demo/grading phase. | Essential for debugging. |

## 2\. Project Overview

| Category | Details |
| :---- | :---- |
| Overall Goal | Provide a platform where users can match different mods to parts to create custom builds with a community section where users can share builds, ask questions and communicate. This uses a drag-and-drop system to add/remove parts to a build. |
| Target Audience | People who are just starting out with cars and want to mod their car and improve performance. |
| Core Features | Data structure with SQL tables for cars and parts, junction tables for compatibility and builds. Community posts with communication (comments/replies). Admin panel for content management. User accounts. Affiliate linking for monetization. |
| Part Categories | Exhaust, Intake, Suspension, Wheels (Rims), Tires, Brakes. |

## 3\. File Structure

The two files required based on executive decisions have been added.

| File | Simple Description |
| :---- | :---- |
| config.php | Holds MySQL credentials and environment settings. |
| redirect.php | Logs affiliate link clicks to click\_logs before redirecting. |
| index.php | Landing page, browsing car models & parts, The Build Area (Core App) |
| community.php | Community/Forum page for shared builds |
| user/login.php | User login |
| user/logout.php | Logout functionality |
| user/register.php | User registration |
| user/profile.php | User’s account dashboard (created and saved builds) |
| user/editProfile.php | Edit user profile and delete account |
| admin.php | Admin panel and content management |
| headerFooter.php | Shared header/footer module |
| theme/style.css | Main theme (Dark and Light modes required) |
| data.sql | Holds all project data, including initial seeding. |

## 4\. Final MySQL Database Structure

The structure now includes the user\_likes table and specifies the required slot identifiers for position\_data.

| Table Name | Purpose | Key Columns & Notes |
| :---- | :---- | :---- |
| users | Stores user account information. | uid (PK), email (Unique), password\_hash, profileImage (URL to image) |
| cars | Stores car make, model, and year data. | car\_id (PK), name, brand, year, model |
| affiliate\_sources | Stores vendor names and base URLs. | source\_id (PK), source\_name, base\_url |
| parts | Stores all available mod/part data. | part\_id (PK), name, price, color, description, image, link (Affiliate link path), category, source\_id (FK) |
| builds | Stores ALL user-created builds. | build\_id (PK), user\_id (FK), car\_id (FK), build\_title (Required), total\_price, featured\_image (optional URL), date\_created, likes\_count, is\_community\_shared (Boolean) |
| build\_parts | Maps parts to specific builds. | build\_part\_id (PK), build\_id (FK), part\_id (FK), position\_data (JSON string, must contain slot identifiers) |
| admin\_whitelist | Controls access to the admin page. | email (Primary Key, unique authorized email address) |
| user\_saved\_builds | Junction table to track what builds a user has saved/bookmarked. | user\_id (FK), build\_id (FK), date\_saved |
| part\_compatibility | Junction table to define which parts fit which cars. | part\_id (FK), car\_id (FK) |
| click\_logs | Logs individual user clicks on affiliate links for detailed tracking. | click\_id (PK), part\_id (FK), user\_id (FK, Nullable), timestamp, ip\_address |
| user\_likes | NEW: Tracks which users have liked which builds (one like per user). | user\_id (FK), build\_id (FK), timestamp |
| comments | Stores all user comments and replies. | comment\_id (PK), build\_id (FK), user\_id (FK), parent\_comment\_id (Nullable, allows only one level of reply), content, date\_posted |

## 5\. Detailed File Content: Explained

### index.php (Landing Page & The Build Area)

* Initial View: User must select a car make and model first.  
* Access: Users not logged in can view this page. Attempting to save or share redirects to login.php.  
* Layout:  
  * Left Side (Part Selection): List of parts compatible with the selected car. Uses tabs and filtering (Search, Price: Low to High/High to Low, Brand, Color). Filtering must be instant via client-side JavaScript. Parts are drag-and-drop enabled.  
  * Right Side (Build Area):  
    * Top (Up to 80%): Interactive area where parts are placed.  
    * Bottom (20%): Displays "Car make and model," "Total price," and "Share/Save button."  
* Technical Logic:  
  * PHP must connect to the SQL database using credentials from config.php.  
  * PHP queries parts compatible with the selected car (parts JOIN part\_compatibility).  
  * Drag-and-Drop: Uses plain JavaScript. When a part is dropped, JS checks part\_compatibility.  
  * Placement: Dropped parts must snap into defined slots (e.g., engine\_slot).  
  * Build Array: If compatible, the part's ID and its slot identifier (position\_data) are stored in a JavaScript array/session.  
  * UI Update: Total price and car model update dynamically.  
  * Affiliate Link Tracking: Clicking an external part link must trigger a redirect to redirect.php?part\_id=... to log the click before sending the user to the vendor.  
  * Save/Share Button: Includes a toggle ("Share to Community" checkbox) to set is\_community\_shared to TRUE (public) or FALSE (private).

### headerFooter.php (Shared Module)

* Inclusion: Used via include 'includes/headerFooter.php';.  
* Header (Dynamic):  
  * Title: Centered.  
  * Links: On the right-hand side.  
    * Logged Out: "Sign In," "Register."  
    * Logged In (Non-Admin): "Home," "Community," "Profile."  
    * Logged In (Admin): "Home," "Community," "Admin" (only visible if the user's email is in admin\_whitelist), "Profile."  
* Footer (Static): Shows contact information and a copyright sign.

### redirect.php (Affiliate Click Logger)

* Purpose: Logs affiliate clicks before redirecting the user to the vendor.  
* Logic:  
  1. Receives part\_id via GET parameter.  
  2. Fetches the corresponding part and affiliate source (base URL \+ link path).  
  3. Inserts a record into the click\_logs table: part\_id, user\_id (if logged in, otherwise NULL), timestamp, and ip\_address.  
  4. Redirects the user to the full affiliate URL (base\_url \+ link).

### community.php (Community/Forum)

* Content: Lists builds where is\_community\_shared \= TRUE.  
* Filtering: Filters by car model, budget, or popularity (total likes \+ saves).  
* Build Card Details: Featured image, build title, creator profile, total cost, parts list (with affiliate links), like count, and comments.  
* Forking and Automatic Save Logic (CRITICAL):  
  1. Clicking "Fork Build" checks for an active Work-In-Progress (WIP) build in the user's session.  
  2. If WIP found, it is automatically saved as a private build (is\_community\_shared \= FALSE) to the user's builds table.  
  3. The script then CLONES the parts list and position\_data from the clicked community build's build\_parts into a new private build record in the user's builds table.  
  4. The user is redirected to index.php to load this new cloned build for editing.  
* Comment Threading: Nested comments are restricted to one reply layer deep (parent\_comment\_id is used).  
* Comment Deletion: Delete option visible to the comment creator OR any Admin user. Deleting a parent comment must CASCADE DELETE all associated replies.

### user/profile.php (User Dashboard)

* Authentication: Requires an active session.  
* Layout:  
  * User Card: Displays username, profile\_image (URL). Includes an "editProfile" link visible only to the profile owner.  
  * Builds Section (Tabs/Sections):  
    * My Original Builds: Lists user's created builds (builds filtered by user\_id). Includes a Delete button.  
    * My Saved Builds: Lists bookmarked community builds (user\_saved\_builds JOIN builds).  
* Technical Logic:  
  * Build lists ordered newest to oldest.  
  * Build Deletion (CRITICAL): Deleting an original build must DELETE corresponding records from build\_parts. It must NOT delete entries from user\_saved\_builds (keeping other users' saved copies intact).

### user/editProfile.php (Profile Editor)

* Functionality: Update username, email, and password.  
* Account Deletion (CRITICAL):  
  * The user must have a clear option to delete the account permanently.  
  * The user must confirm by re-entering their password.  
  * Deletion must DELETE the user's record from users and CASCADE DELETE all related records: builds, build\_parts, click\_logs, user\_saved\_builds, and comments. Builds created by the deleted user must remain accessible to others who saved them (forking rule).

### admin.php (Admin Panel)

* Access: Strictly limited to users whose email addresses are listed in the admin\_whitelist SQL table. Unauthorized users are immediately redirected to index.php using header().  
* Dashboard (Main Content): Displays monetization metrics from click\_logs.  
  * Line Chart: Total clicks over time (days/weeks/months) for a selected part category (using Chart.js).  
  * Pie Chart: Percentage of Affiliate Clicks by Part Category OR Affiliate Source (using Chart.js).  
  * Bar Chart ("Clicks per Part"): Click count for individual parts, site grand total, and highest-clicked part (using Chart.js).  
  * Revenue Display: Only click metrics are shown, no estimated revenue.  
* Content Management (Navigation Sidebar):  
  * A. Car Management (CRUD): Interface for cars table. Deleting a car must CASCADE DELETE associated records in part\_compatibility.  
  * B. Part Management (CRUD): Interface for parts table. Includes mechanism to link the part to one or more cars (modifying part\_compatibility). Deleting a part must DELETE the part and CASCADE DELETE associated records in part\_compatibility.  
  * C. Affiliate Source Management (CRUD): Interface for affiliate\_sources table. Deleting an affiliate source must UPDATE the source\_id of all linked records in the parts table to NULL.

## 6\. Aesthetics and Monetization

### CSS Theme (theme/style.css)

All components must be fully responsive.

| Theme | Background | Card / Panel | Text Primary | Accent 1 (CTA Buttons) | Accent 2 (Hover/Links) | Borders/Lines |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| Dark Mode | \#1B1C1E | \#2A2C2F | \#E5E7EB (off-white) | \#DC2626 (red) | \#F97316 (Orange) | \#3F4042 |
| Light Mode | \#F4F4F5 (very light gray) | \#FFFFFF | \#1F2937 (dark gray) | \#DC2626 (Red) | \#EA580C (Orange) | \#D1D5DB |

### Monetization Strategy

* Strategy: Single stream based entirely on Affiliate Links. No paywall or subscription.  
* Revenue Goal: Capture approximately 35% of revenue earned from successful purchases.  
* Link Construction: Dynamically constructed by combining base\_url (from affiliate\_sources) and link (path from parts). Format: base\_url \+ link.

### Initial Data Requirement (data.sql)

data.sql must include:

* One base car: Honda Civic.  
* Example parts for the Civic, linked to at least one affiliate source.  
* One Admin user account: michaeltest@modmycar.com (password hash for admin123).  
* The email michaeltest@modmycar.com in the admin\_whitelist table