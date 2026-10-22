# SLOCloud Documentation

Documentation is organized into two categories:

## Concepts (`concepts/`)

**Purpose**: Architectural decisions and system design

**Content**: High-level "what" and "why"
- System architecture and component purposes
- Design decisions and rationale
- Non-negotiable requirements
- Relationships between components
- Security model and constraints

**When to read**: Before making architectural decisions or implementing major features

**Files**:
- [ARCHITECTURE.md](concepts/ARCHITECTURE.md) - Overall system design
- [SCHEMA.md](concepts/SCHEMA.md) - Database schema and relationships
- [CODE_ORGANIZATION.md](concepts/CODE_ORGANIZATION.md) - Code structure and patterns
- [PLUGIN.md](concepts/PLUGIN.md) - Plugin system architecture
- [AUTH.md](concepts/AUTH.md) - Authentication and authorization
- [SECURITY.md](concepts/SECURITY.md) - Security requirements
- [TESTING.md](concepts/TESTING.md) - Testing strategy
- [ACCESSIBILITY.md](concepts/ACCESSIBILITY.md) - Accessibility standards

## Implementation (`implementation/`)

**Purpose**: Step-by-step development patterns

**Content**: Detailed "how-to"
- Implementation workflows  
- Code patterns and examples
- Common operations and tasks
- Best practices
- Error handling strategies

**When to read**: During active development of features

**Files**:
- [CODE_GUIDE.md](implementation/CODE_GUIDE.md) - Practical implementation patterns
- [PLUGIN_GUIDE.md](implementation/PLUGIN_GUIDE.md) - Plugin development guide
- [DATA_CONNECTORS.md](implementation/DATA_CONNECTORS.md) - Connector implementation patterns

## Design Notes (`docs/`)

**Purpose**: Feature-specific design and rationale for subsystems.

- [DYNAMIC_REPORTS.md](DYNAMIC_REPORTS.md) - Dynamic reporting subsystem
- [UPDATE_WIZARD_DESIGN.md](UPDATE_WIZARD_DESIGN.md) - In-admin update wizard (schema-stable self-update vs. schema-changing guided update)

## Upgrades (`upgrades/`)

**Purpose**: Per-release database upgrade plans (preflight checks, steps, verification, rollback) for every release that changes the schema. Start at [upgrades/README.md](upgrades/README.md).

## Documentation Philosophy

**Concepts are language-agnostic** - Can be implemented in any language while preserving architectural intent

**Implementation guides are specific** - Provide concrete patterns for current technology stack (PHP 8.1+, MySQL 8.0+ or SQL Server 2016+ via PDO)

**Separation enables portability** - If reimplementing in different language, concepts remain valid while implementation details change
