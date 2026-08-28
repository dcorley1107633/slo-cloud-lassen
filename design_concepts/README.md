# SLOCloud

**Modular Outcomes System for Achievement and Institutional Compliance**

SLOCloud is an open-source Student Learning Outcomes (SLO) assessment platform for higher education that eliminates manual data entry and streamlines accreditation reporting through seamless LMS integration.

---

## Vision

Replace fragmented, manual assessment processes with an integrated, automated system that:
- Reduces faculty administrative burden by 85%
- Provides real-time visibility into learning outcomes across the institution
- Generates accreditation-ready reports with minimal effort
- Integrates directly into existing teaching workflows

## Core Promises

### Seamless LMS Integration
- **LTI 1.1/1.3 Compliant:** Connect with Canvas, Blackboard, Moodle, and other LMS platforms
- **Zero Manual Entry:** Faculty submit assessments directly from their LMS with one-click launches
- **Auto-Provisioning:** Students and course data flow automatically from the LMS

### Real-Time Analytics & Reporting
- **Multi-Dimensional Analysis:** Filter and analyze outcomes by course, discipline, program, and term
- **Powerful Visualizations:** Track trends and patterns across the institution
- **Drill-Down Capabilities:** Move from institution-wide metrics to individual course sections
- **Term-Over-Term Comparisons:** Identify improvement areas and successful interventions

### Accreditation Support
- **Standards-Aligned Reports:** Generate comprehensive accreditation reports in minutes, not weeks
- **Evidence Documentation:** Track assessment methods and store evidence artifacts
- **Alignment Mapping:** Visualize connections between course SLOs, program outcomes, and institutional outcomes
- **Audit Trail:** Complete history of assessments and modifications

### Faculty-Friendly Experience
- **Intuitive Interface:** Simple, purpose-built tools that respect faculty time
- **Bulk Operations:** Enter multiple student assessments at once
- **Flexible Assessment:** Optional numeric scoring or simple proficiency indicators
- **Minimal Training:** Instructors can start using the system immediately

### Institutional Hierarchy
- **Complete Assessment Chain:** Institution → Programs → Courses → Students
- **Flexible Structure:** Support multiple departments, programs, and outcome frameworks
- **Role-Based Access:** Appropriate views for administrators, coordinators, and faculty
- **FERPA Compliant:** Secure handling of student assessment data

---

## Key Features

### For Assessment Coordinators & Administrators
- Dashboard with institution-wide outcome achievement metrics
- SLO administration and program outcome alignment tools
- Bulk SLO upload and management
- Custom report generation
- Student enrollment tracking across courses

### For Faculty & Instructors
- LTI-integrated assessment entry launched from LMS
- Pre-populated course and student rosters
- Simple SLO mapping interface
- Bulk student assessment submission
- Course-level outcome summaries

### For Institutional Research
- Export capabilities for external analysis
- Historical trend data
- Cross-program comparisons
- Data connector plugins for SIS/LMS integration

---

## Target Metrics

| Metric | Goal |
|--------|------|
| **Time Savings** | 85% reduction in assessment administrative work |
| **Faculty Adoption** | 90%+ participation rate in first year |
| **Report Generation** | Accreditation reports in <10 minutes |
| **Data Accuracy** | >99% accuracy through automated data flow |
| **LTI Compliance** | 100% standards-compliant implementation |

---

## Technology Approach

- **Modern PHP Stack:** PHP 8.1+ with MySQL 8.0+
- **Standards-Based:** LTI 1.1/1.3, OAuth
- **Plugin Architecture:** Extensible for institution-specific needs
- **AdminLTE 4 UI:** Responsive, accessible admin dashboard framework
- **Security-First:** FERPA-compliant data handling, prepared statements, CSRF protection

---

## Project Status

**Current Phase:** Early development with comprehensive design documentation and working prototypes.

-  Complete design specifications in `design_concepts/`
-  Working demo implementations in `slocloud-slo/demo/` using AdminLTE 4
-  Core MVC architecture implementation in progress
-  Authentication systems (local, LTI) planned
-  Plugin system specification complete

---

## Design Philosophy

**Simplicity Over Flexibility**

We prioritize usability and pragmatic solutions over theoretical flexibility:

-  **Opinionated Choices:** MySQL-only, PHP 8.1+, AdminLTE 4 (no alternatives)
-  **Clear Requirements:** Hard technical requirements prevent compatibility chaos
-  **Concrete Implementation:** Ship working features, not abstract frameworks
-  **Strategic Flexibility:** Plugin system for real institutional differences
-  **No Premature Abstraction:** Build what's needed now, not what might be needed later

**User-Centered Design**

- Faculty time is precious—minimize clicks and cognitive load
- Administrators need visibility—provide powerful analytics without complexity
- Students are stakeholders—protect their data and privacy rigorously
- Institutions vary—support customization through plugins, not core code modification

---

## Documentation

### Design Specifications (`docs/concepts/`)
- **[ARCHITECTURE.md](../docs/concepts/ARCHITECTURE.md)** - System architecture and entity relationships
- **[SCHEMA.md](../docs/concepts/SCHEMA.md)** - Complete database schema
- **[AUTH.md](../docs/concepts/AUTH.md)** - Authentication systems (local, LTI, SAML)
- **[SECURITY.md](../docs/concepts/SECURITY.md)** - Security requirements and practices
- **[PLUGIN.md](../docs/concepts/PLUGIN.md)** - Plugin architecture specification
- **[TESTING.md](../docs/concepts/TESTING.md)** - Testing strategy and coverage
- **[ACCESSIBILITY.md](../docs/concepts/ACCESSIBILITY.md)** - WCAG compliance guidelines

### Implementation (`slocloud-slo/`)
- **Demo Applications:** Working prototypes in `slocloud-slo/demo/` using AdminLTE 4 framework
- **MVC Structure:** Models, Controllers, Views (planned)
- **Core Components:** Authentication, routing, database abstraction (planned)

---

## Getting Started

### Prerequisites
- PHP 8.1 or higher
- MySQL 8.0 or higher
- Web server (Apache/Nginx) or PHP built-in server for development

### Local Development
```powershell
# Clone repository
git clone https://github.com/tqtran/slocloud-slo.git
cd slocloud-slo

# Start PHP development server
php -S localhost:8000

# Access demo portal
http://localhost:8000/slocloud-slo/demo/
```

### Explore Demos
- **Admin Dashboard:** Analytics and reporting interface
- **SLO Administration:** Outcome hierarchy management
- **Instructor Interface:** Assessment data entry simulation
- **Student Management:** Enrollment tracking

---

## Contributing

This project is in early development. Contributions should align with design specifications in `design_concepts/`. Before implementing features:

1. **Read relevant design docs** - All implementations must follow specifications
2. **Start with demos** - Prototype in `slocloud-slo/demo/` first
3. **Follow conventions** - Naming, security patterns, and MVC structure
4. **Keep it simple** - Resist overengineering and premature abstraction

---

## License

[License TBD]

---

## Contact

**Project Owner:** tqtran  
**Repository:** https://github.com/tqtran/slocloud-slo

---

*SLOCloud - Making assessment meaningful, not just mandatory.*
