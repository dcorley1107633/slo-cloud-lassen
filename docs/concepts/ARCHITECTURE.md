# SLOCloud Architecture

## Overview

SLOCloud (Modular Outcomes System for Achievement and Institutional Compliance) is an open-source Student Learning Outcomes assessment platform with LTI integration support.

## Directory Structure

```
slocloud-slo/
├── docs/                  # Architecture & implementation documentation
│   ├── concepts/         # High-level architecture decisions
│   └── implementation/   # Step-by-step implementation guides
├── src/                   # Web root - drop-in install directory
│   ├── index.php         # Application entry point
│   ├── dashboard/        # Dashboard pages (outcomes, institution, etc.)
│   ├── lti/              # LTI integration endpoints
│   ├── setup/            # Installation wizard
│   ├── scripts/          # CLI maintenance scripts
│   ├── includes/         # Shared page components
│   ├── config/           # Configuration files
│   ├── Core/             # Core framework classes
│   ├── Models/           # Optional data access classes
│   ├── database/         # Database schema files
│   ├── assets/           # Static assets
│   │   ├── css/          # Stylesheets
│   │   └── js/           # JavaScript files
│   └── plugins/          # Plugin directory
│       └── local/        # Local plugins
├── database/              # Database files
│   └── schema.sql        # Master schema file
├── logs/                  # Application logs
└── README.md             # Project overview
```

## Drop-in Installation

SLOCloud is designed as a drop-in install where the `src/` directory is the web root. Point your web server document root at `src/` and you're ready to go.

**Security Requirements:**

Since `src/` is web-accessible, protect sensitive directories:

1. **config/** - Database credentials, API keys
   - Add `.htaccess` with `Deny from all` (Apache)
   - Add `index.php` that exits immediately (fallback)
   - Never commit `config.yaml` to version control

2. **logs/** - Stored at project root, outside web root

3. **uploads/** (if implemented) - User-uploaded files
   - Add `.htaccess` with appropriate restrictions
   - Serve files through PHP script with authentication checks

**Example .htaccess for src/config/:**
```apache
Deny from all
```

## Code Organization

### Page-Based Structure
- Direct mapping: URL → file → response
- Pages organized by feature area (`dashboard/`, `lti/`, etc.)
- Optional Models for shared data access patterns
- Common includes for consistent framework asset loading
- See [CODE_ORGANIZATION.md](CODE_ORGANIZATION.md)

### Database Layer
- MySQL 8.0+ or SQL Server 2016+ (PDO-based)
- Assessment data storage
- LTI consumer management
- Prepared statements for security
- See [SCHEMA.md](SCHEMA.md)

## Assessment Hierarchy

```
Institution
    └── Institutional Outcomes
        └── Program Outcomes
            └── Programs

SLO Sets (per year/quarter/period)
    ├── Terms
    │   └── Course Sections (with CRN)
    │       └── Enrollments
    │           └── Assessments
    └── Student Learning Outcomes (SLOs)
        └── Mapped to Program Outcomes
```

**Key Concepts:**
- **SLO Sets**: SLOs are grouped by time period (academic year, quarter, semester, etc.)
- **Terms**: Each academic term is linked to an SLO Set, determining which SLOs are assessed
- **CRN**: Each course section has a unique Course Reference Number for registration and LTI integration
- **Enrollments**: Students enroll in course sections (which have CRNs)
- **Assessments**: Tied to enrollment records for specific SLOs from the term's SLO Set

## Key Features

1. **Multi-level Assessment Tracking**
   - Institutional outcomes
   - Program outcomes
   - Student learning outcomes
   - Individual student assessments

2. **Organizational Structure**
   - Departments
   - Programs
   - Courses
   - Course sections (by term)

3. **User Management**
   - Role-based access control
   - Context-aware permissions
   - Audit trail tracking

4. **LTI Integration**
   - LTI 1.1/1.3 support
   - Consumer key management
   - Secure launch validation

## Entity Relationship Diagram

```
institution
    └─> institutional_outcomes
        └─> program_outcomes
            └─> programs

slo_sets (per year/quarter/period)
    ├─> terms
    │   └─> course_sections (with CRN)
    │       └─> enrollments
    │           ├─> students
    │           └─> assessments
    └─> student_learning_outcomes
        └─> courses

departments
    ├─> programs
    └─> courses

users
    ├─> user_roles -> roles
    ├─> course_sections (instructor)
    └─> audit fields (created_by, updated_by, assessed_by)
```

## Technology Stack

- **Backend**: PHP 8.1+ (required)
- **Database**: MySQL 8.0+ or SQL Server 2016+ (via PDO)
  - PDO-based data layer with prepared statements
  - Configure driver in config.yaml: `driver: mysql` or `driver: mssql`
  - Schema files: `schema.sql` (MySQL) and `schema_mssql.sql` (SQL Server)
  - For external system integration, use data connector plugins
- **Web Server**: Apache, Nginx, or any PHP 8.1+ compatible server
- **Frontend**: 
  - AdminLTE 4.0 (admin dashboard framework)
  - Bootstrap 5.3.2 (CSS framework, included with AdminLTE)
  - jQuery 3.7.0 (optional, for custom scripting)
  - Font Awesome 6.4.0 (icons)
  - All frameworks loaded via CDN from `src/system/includes/header.php`

## Security Features

- Password hashing (Argon2id)
- SQL injection prevention (prepared statements)
- XSS protection (output escaping)
- CSRF protection
- Role-based access control
- Audit logging

## Documentation

- [SCHEMA.md](SCHEMA.md) - Complete database schema
- [CODE_ORGANIZATION.md](CODE_ORGANIZATION.md) - Code structure and patterns
- [CODE_GUIDE.md](../implementation/CODE_GUIDE.md) - Implementation patterns
