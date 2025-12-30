# SafeSpace - people Safety Platform 🛡️

A comprehensive web-based safety platform designed to empower women and communities through incident reporting, real-time alerts, community support, and emergency response features.

## 🌟 Overview

SafeSpace is a full-stack web application that provides a secure environment for reporting safety incidents, tracking safety zones, accessing emergency services, and building community support networks. The platform combines modern web technologies with real-time communication to create a responsive and reliable safety ecosystem.

## ✨ Key Features

### 🔐 Authentication & User Management
- **Multi-Provider Authentication**: Firebase integration supporting:
  - Email/Password authentication
  - Google OAuth
  - Facebook OAuth
  - GitHub OAuth
- **Session Management**: Secure PHP session handling with MySQL synchronization
- **User Profiles**: Customizable user profiles with display names and preferences
- **Multi-language Support**: English and Bengali (বাংলা) language options

### 📊 Incident Reporting System
- **Comprehensive Reporting**: Report various incident types including:
  - Harassment
  - Assault
  - Theft
  - Vandalism
  - Stalking
  - Cyberbullying
  - Discrimination
- **Severity Levels**: Low, Medium, High, Critical
- **Evidence Upload**: Support for multiple file attachments (images, documents)
- **Location Tracking**: GPS-based location tagging with map integration
- **Anonymous Reporting**: Option to submit reports anonymously
- **Public/Private Reports**: Control report visibility

### 🗺️ Interactive Safety Map
- **Real-time Zone Visualization**: Color-coded safety zones:
  - 🟢 **Green (Safe)**: 0-2 incidents
  - 🟡 **Yellow (Moderate)**: 3-4 incidents
  - 🔴 **Red (Unsafe)**: 5+ incidents
- **Safe Space Markers**: Parks, police stations, hospitals, community centers
- **Incident Heatmap**: Visual representation of incident density
- **Nearby Safe Spaces**: Find safe locations within radius
- **Walk-with-Me Feature**: Real-time location sharing for safety

### 🚨 Emergency Features
- **Panic Button**: One-touch emergency alert system
- **Emergency Contacts**: Manage and notify trusted contacts
- **SOS Alerts**: Automatic notification to emergency contacts
- **Real-time Location Sharing**: Live GPS tracking during emergencies
- **Walk Sessions**: Track and monitor walking routes with emergency triggers

### 👥 Community Features
- **Neighborhood Groups**: Create and join local safety groups
- **Group Alerts**: Share safety warnings within communities
- **Media Gallery**: Share photos and documents within groups
- **Member Management**: Roles (Founder, Admin, Moderator, Member)
- **Contribution Scoring**: Gamification for active community participation

### 📚 Support Services
- **Legal Aid**: Directory of legal service providers
- **Medical Support**: Access to medical facilities and counseling
- **Safety Education**: Training courses and certification
- **Resource Library**: Safety tips and educational materials
- **Document Management**: Store and access legal documents

### 📈 Analytics & Reporting
- **Safety Scores**: Area-based safety ratings
- **Trend Analysis**: Incident patterns and statistics
- **Response Time Tracking**: Monitor incident resolution times
- **Category Distribution**: Breakdown of incident types
- **User Activity**: Track engagement and contributions

### 👨‍💼 Admin Dashboard
- **Comprehensive Oversight**: Monitor all platform activities
- **Approval System**: Review and approve:
  - Incident reports
  - Community groups
  - Service providers
  - Disputes
- **Real-time Analytics**: Live charts and statistics
- **User Management**: Manage user accounts and permissions
- **System Diagnostics**: Database health and performance metrics

## 🛠️ Technology Stack

### Frontend
- **HTML5/CSS3**: Modern semantic markup and styling
- **JavaScript (ES6+)**: Interactive functionality
- **TailwindCSS**: Utility-first CSS framework
- **Chart.js & ApexCharts**: Data visualization
- **Leaflet.js**: Interactive mapping
- **Three.js**: 3D animations and effects
- **Firebase SDK**: Client-side authentication

### Backend
- **PHP 8.2+**: Server-side logic
- **MySQL/MariaDB**: Relational database
- **Node.js**: WebSocket server for real-time features
- **Express.js**: API endpoints
- **WebSocket (ws)**: Real-time communication
- **Ratchet**: PHP WebSocket library

### Database Features
- **Spatial Data**: MySQL POINT type for geolocation
- **Stored Procedures**: Automated zone status updates
- **Triggers**: Audit logging and data integrity
- **Functions**: Haversine distance calculations
- **Full-Text Search**: Efficient content searching

### Real-time Features
- **WebSocket Server**: Live updates for:
  - Map markers
  - Incident reports
  - Emergency alerts
  - Walk sessions
- **Broadcasting**: Multi-client synchronization
- **Event-driven Architecture**: Pub/sub pattern

## 📁 Project Structure

```
space-login/
├── api/                          # API endpoints
│   ├── walk_control.php         # Walk session management
│   └── twiml_emergency.php      # Emergency call handling
├── includes/                     # Core PHP classes
│   ├── Database.php             # Database abstraction layer
│   ├── NotificationSender.php   # Notification system
│   ├── admin_nav.php            # Admin navigation
│   └── broadcast_map_update.php # Map update broadcasting
├── js/                          # JavaScript modules
│   ├── firebase-config.js       # Firebase configuration
│   ├── suppress-tailwind-warning.js
│   └── websocket-map-client.js  # WebSocket client
├── scripts/                     # Utility scripts
│   └── seed-leafnodes.js        # Database seeding
├── uploads/                     # User-uploaded files
│   └── evidence/                # Incident evidence files
├── vendor/                      # Composer dependencies
├── node_modules/                # NPM dependencies
│
├── index.html                   # Landing/Login page
├── register.html                # User registration
├── dashboard.php                # Main user dashboard
├── admin_dashboard.php          # Admin control panel
├── profile.php                  # User profile management
├── settings.php                 # User settings
│
├── report_incident.php          # Incident reporting form
├── view_report.php              # View incident details
├── view_public_reports.php      # Public reports listing
├── my_reports.php               # User's reports
├── edit_report.php              # Edit incident reports
│
├── safe_space_map.php           # Interactive safety map
├── safety_scores.php            # Area safety ratings
├── area_detail.php              # Area-specific information
│
├── community_groups.php         # Community group management
├── group_detail.php             # Group details and activity
├── create_group.php             # Create new group
├── group_alerts.php             # Group alert system
├── group_media_gallery.php      # Group media sharing
│
├── panic_button.php             # Emergency panic feature
├── emergency_contacts.php       # Emergency contact management
├── walk_with_me.php             # Walk tracking feature
├── my_emergencies.php           # Emergency history
│
├── legal_aid.php                # Legal service directory
├── medical_support.php          # Medical service directory
├── safety_education.php         # Training and courses
├── safety_resources.php         # Resource library
├── my_training.php              # User's training progress
│
├── dispute_center.php           # Dispute resolution
├── community_alerts.php         # Community-wide alerts
├── missing_person_alerts.php    # Missing person reports
│
├── server.js                    # Node.js API server
├── websocket-broadcast-server.js # WebSocket server
├── START_REALTIME_SERVERS.bat   # Server startup script
│
├── db.php                       # Database connection
├── auth.php                     # Authentication helpers
├── .env                         # Environment configuration
├── package.json                 # Node.js dependencies
├── composer.json                # PHP dependencies
├── space_login.sql              # Database schema
└── space_login updated.sql      # Updated database schema
```

## 🚀 Installation & Setup

### Prerequisites
- **XAMPP** (or similar LAMP/WAMP stack)
  - PHP 8.2 or higher
  - MySQL 5.7+ or MariaDB 10.4+
  - Apache 2.4+
- **Node.js** 16+ and npm
- **Composer** (PHP dependency manager)
- **Firebase Account** (for authentication)

### Step 1: Clone/Download Project
```bash
# Place the project in your XAMPP htdocs directory
cd c:\xampp\htdocs\
# Your project should be at: c:\xampp\htdocs\space-login\
```

### Step 2: Database Setup
1. Start XAMPP and ensure MySQL is running
2. Open phpMyAdmin (http://localhost/phpmyadmin)
3. Create a new database named `space_login`
4. Import the database schema:
   ```sql
   # Import: space_login updated.sql
   ```
5. The database includes:
   - 30+ tables with relationships
   - Stored procedures and functions
   - Triggers for audit logging
   - Sample data for testing

### Step 3: Environment Configuration
1. Copy `.env` file and configure:
   ```env
   DB_HOST=localhost
   DB_USER=root
   DB_PASSWORD=
   DB_NAME=space_login
   PORT=3000
   NODE_ENV=development
   ```

### Step 4: Install Dependencies

#### PHP Dependencies
```bash
cd c:\xampp\htdocs\space-login
composer install
```

#### Node.js Dependencies
```bash
npm install
```

### Step 5: Firebase Setup
1. Create a Firebase project at https://console.firebase.google.com
2. Enable Authentication providers:
   - Email/Password
   - Google
   - Facebook (optional)
   - GitHub (optional)
3. Update Firebase configuration in `index.html` and `register.html`:
   ```javascript
   const firebaseConfig = {
     apiKey: "YOUR_API_KEY",
     authDomain: "YOUR_AUTH_DOMAIN",
     projectId: "YOUR_PROJECT_ID",
     storageBucket: "YOUR_STORAGE_BUCKET",
     messagingSenderId: "YOUR_MESSAGING_SENDER_ID",
     appId: "YOUR_APP_ID",
     measurementId: "YOUR_MEASUREMENT_ID"
   };
   ```

### Step 6: Start Servers

#### Apache/MySQL (XAMPP)
- Start Apache and MySQL from XAMPP Control Panel

#### Node.js Servers
```bash
# Option 1: Start all servers at once (Windows)
START_REALTIME_SERVERS.bat

# Option 2: Start individually
# Terminal 1 - API Server
npm start

# Terminal 2 - WebSocket Server
npm run broadcast
```

### Step 7: Access the Application
- **Main Application**: http://localhost/space-login/
- **Admin Dashboard**: http://localhost/space-login/admin_dashboard.php
- **API Server**: http://localhost:3000

## 👤 Default Admin Account

After importing the database, you can create an admin account:

1. Register a new user through the registration page
2. Update the user in the database:
   ```sql
   UPDATE users SET is_admin = 1 WHERE email = 'your-email@example.com';
   ```

Or use the pre-configured admin:
- **Email**: admin@safespace.com
- **Password**: (Set during registration)

## 📖 Usage Guide

### For Users

#### Reporting an Incident
1. Log in to your account
2. Navigate to "Report Incident" from the dashboard
3. Fill in incident details:
   - Title and description
   - Category and severity
   - Location (auto-detected or manual)
   - Upload evidence (optional)
   - Choose anonymous/public options
4. Submit the report for review

#### Using the Safety Map
1. Go to "Safety Map" from the menu
2. View color-coded zones:
   - Green: Safe areas
   - Yellow: Moderate risk
   - Red: High risk
3. Click markers for details
4. Find nearby safe spaces
5. Use "Walk with Me" for real-time tracking

#### Emergency Features
1. **Panic Button**: Quick access from dashboard
2. **Emergency Contacts**: Add trusted contacts in settings
3. **Walk Sessions**: Start tracking before walking alone
4. **SOS Alerts**: Automatic notifications to contacts

#### Community Participation
1. Join or create neighborhood groups
2. Share alerts and safety information
3. Participate in discussions
4. Earn contribution points

### For Administrators

#### Accessing Admin Dashboard
1. Log in with admin credentials
2. Navigate to admin_dashboard.php
3. View comprehensive analytics and metrics

#### Approving Content
1. Navigate to "Pending Approvals" section
2. Review pending items:
   - Incident reports
   - Community groups
   - Service providers
   - Disputes
3. Approve or reject with notes

#### Managing Users
1. View user statistics
2. Monitor user activity
3. Manage user permissions
4. Handle disputes and reports

#### System Monitoring
1. View real-time analytics
2. Monitor database health
3. Track system performance
4. Review audit logs

## 🔧 Configuration

### Database Configuration
Edit `db.php` or `.env`:
```php
$host = 'localhost';
$dbname = 'space_login';
$username = 'root';
$password = '';
```

### WebSocket Configuration
Edit `server.js` and `websocket-broadcast-server.js`:
```javascript
const PORT = process.env.PORT || 3000;
const WS_PORT = 8080;
```

### File Upload Settings
Configure in `report_incident.php`:
```php
$maxFileSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
```

## 🗄️ Database Schema

### Core Tables
- **users**: User accounts and authentication
- **incident_reports**: Safety incident records
- **incident_zones**: Aggregated safety zones
- **alerts**: System-wide alerts
- **emergency_contacts**: User emergency contacts
- **walk_sessions**: Walk tracking sessions

### Community Tables
- **neighborhood_groups**: Community groups
- **group_members**: Group membership
- **group_alerts**: Group-specific alerts
- **group_media**: Shared media files

### Support Tables
- **legal_aid_providers**: Legal service directory
- **medical_support_providers**: Medical service directory
- **safety_education_courses**: Training courses
- **course_enrollments**: User course progress

### Administrative Tables
- **disputes**: Report disputes
- **audit_logs**: System audit trail
- **area_safety_scores**: Area safety ratings

## 🔐 Security Features

- **SQL Injection Protection**: Prepared statements throughout
- **XSS Prevention**: Input sanitization and output encoding
- **CSRF Protection**: Token-based form validation
- **Session Security**: Secure session management
- **File Upload Validation**: Type and size restrictions
- **Password Hashing**: Bcrypt encryption
- **Audit Logging**: Comprehensive activity tracking
- **Role-Based Access Control**: User permission system

## 📱 Responsive Design

- Mobile-first approach
- Responsive layouts for all screen sizes
- Touch-friendly interfaces
- Progressive Web App (PWA) ready
- Optimized for performance

## 🌐 Browser Support

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Opera 76+

## 🐛 Troubleshooting

### Database Connection Issues
```bash
# Check MySQL service
# Verify credentials in .env
# Ensure database exists
```

### WebSocket Connection Failed
```bash
# Verify Node.js servers are running
# Check firewall settings
# Ensure ports 3000 and 8080 are available
```

### File Upload Errors
```bash
# Check folder permissions: uploads/evidence/
# Verify PHP upload_max_filesize setting
# Ensure adequate disk space
```

### Firebase Authentication Issues
```bash
# Verify Firebase configuration
# Check API keys and domains
# Enable authentication providers in Firebase Console
```

## 🤝 Contributing

This is a safety-focused platform. Contributions should prioritize:
1. User privacy and security
2. Accessibility features
3. Performance optimization
4. Multi-language support
5. Mobile responsiveness

## 📄 License

This project is developed for educational and community safety purposes.

## 🙏 Acknowledgments

- Firebase for authentication services
- Leaflet.js for mapping capabilities
- Chart.js and ApexCharts for data visualization
- TailwindCSS for styling framework
- The open-source community

## 📞 Support

For issues, questions, or contributions:
- Review the code documentation
- Check the troubleshooting section
- Consult the database schema
- Review audit logs for errors

## 🔮 Future Enhancements

- [ ] Mobile native applications (iOS/Android)
- [ ] AI-powered incident analysis
- [ ] Multi-city expansion
- [ ] Advanced analytics dashboard
- [ ] Integration with law enforcement systems
- [ ] SMS/WhatsApp alert integration
- [ ] Offline mode support
- [ ] Voice-activated emergency features
- [ ] Blockchain-based evidence verification
- [ ] Machine learning for safety predictions

---

**Built with ❤️ for community safety and empowerment**

*Last Updated: November 2025*
