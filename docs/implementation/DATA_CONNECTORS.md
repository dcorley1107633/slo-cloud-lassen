# Data Connector Implementation Guide

This guide provides step-by-step patterns for building data connector plugins as described in [../concepts/PLUGIN.md](../concepts/PLUGIN.md).

## What is a Data Connector?

Data connectors synchronize data between SLOCloud and external systems (Student Information Systems, Learning Management Systems, data warehouses, etc.) without modifying core database schema.

**Key principle**: External systems remain authoritative for their data. Connectors import/export data, maintaining separate ID mappings.

## Architecture Pattern

### Three-Layer Model

1. **External System Layer** - Source of truth for external data
2. **Mapping Layer** - Bidirectional ID mappings (connector-specific tables)
3. **Core Data Layer** - SLOCloud's internal data (uses core models)

### ID Mapping Strategy

**Why mappings are needed:**
- External IDs differ from internal auto-increment PKs
- Same student may have different IDs in SIS vs SLOCloud
- Need to track which external records correspond to which internal records

**Mapping table structure:**
- `external_id` - ID from external system (VARCHAR)
- `internal_id` - SLOCloud primary key (INT)
- `entity_type` - Type of entity (student, course, section, etc.)
- `sync_status` - Current state (pending, synced, error)
- `last_synced_at` - Timestamp of last sync
- `error_message` - Details if sync failed

**Table setup:**
- Unique constraint on (external_id, entity_type)
- Index on (internal_id, entity_type) for reverse lookups
- Named: `{connector_id}_mappings`

## Implementation Steps

### Step 1: Create Mapping Table

In connector's `activate()` method:

1. Define table schema matching above pattern
2. Use connector ID as table prefix
3. Execute CREATE TABLE statement
4. Include proper indexes
5. Return success/failure boolean

**Design considerations:**
- Plan entity types you'll sync (student, course, section, etc.)
- Include error tracking fields
- Add sync timestamp for incremental sync
- Consider soft delete flag if needed

### Step 2: External API Integration

#### Configuration Pattern

Store external system credentials and settings:
- API endpoint URL
- Authentication credentials (API key, OAuth tokens, etc.)
- Sync preferences (auto-create entities, sync frequency)
- Timeout settings
- Cache duration

**Security:**
- Store secrets in environment variables
- Never commit credentials to code
- Use HTTPS for API calls
- Validate SSL certificates

#### API Communication Pattern

For fetching data from external system:

1. **Build Request**
   - Construct URL with endpoint and parameters
   - Add authentication headers
   - Set timeout
   - Set accept header (JSON, XML, etc.)

2. **Execute Request**
   - Use HTTP client (cURL, Guzzle, etc.)
   - Handle connection errors
   - Check HTTP status code
   - Parse response body

3. **Handle Errors**
   - Network errors (timeout, DNS, connection refused)
   - HTTP errors (4xx, 5xx status codes)
   - Parse errors (invalid JSON/XML)
   - Implement retry logic with exponential backoff

4. **Return Data**
   - Parse into array/object structure
   - Validate response structure
   - Return normalized data

### Step 3: Data Import Pattern

#### Student Import Workflow

For importing students from external SIS:

1. **Fetch External Data**
   - Call external API with filters (term, active status, etc.)
   - Receive array of student records
   - Validate response structure

2. **For Each External Student:**
   
   a. **Check Mapping**
   - Query mapping table with external_id
   - Determine if student already imported
   
   b. **Transform Data**
   - Map external field names to internal schema
   - Convert data types if needed
   - Apply business rules (name formatting, etc.)
   - Validate required fields present
   
   c. **Create or Update**
   - If mapping exists: Update existing student via model
   - If no mapping: Create new student via model
   - Get internal primary key from operation
   
   d. **Store Mapping**
   - Insert or update mapping record
   - Link external_id ↔ internal_id
   - Mark entity_type as 'student'
   - Set last_synced_at to current timestamp
   - Set sync_status to 'synced'
   
   e. **Handle Errors**
   - Catch exceptions during create/update
   - Log error with external_id
   - Mark mapping as 'error' with message
   - Continue processing remaining students

3. **Return Summary**
   - Count of records processed
   - Count of new creates vs updates
   - List of errors encountered
   - Total time elapsed

#### Enrollment Sync Workflow

For syncing course enrollment rosters:

1. **Lookup External Section ID**
   - Query mapping table with internal section_id
   - Get corresponding external_id
   - Return error if section not mapped

2. **Fetch Enrollments from External System**
   - Call API with external section ID
   - Receive array of enrolled students
   - Parse student IDs and enrollment metadata

3. **For Each External Enrollment:**
   
   a. **Resolve Student**
   - Lookup internal student ID via mapping
   - Skip if student not found (or trigger student import)
   
   b. **Check Existing Enrollment**
   - Query enrollment table for (section_id, student_id)
   - Determine if enrollment already exists
   
   c. **Create if New**
   - Use enrollment model to create record
   - Include status, enrolled date from external system
   - Link to section and student
   
   d. **Update if Changed**
   - Compare status (enrolled, dropped, withdrawn)
   - Update if status changed in external system

4. **Handle Drops** (optional)
   - Determine students in SLOCloud but not in external roster
   - Mark as dropped if present locally but missing externally
   - Or skip this step to prevent accidental drops

5. **Return Summary**
   - Count of new enrollments
   - Count of updated enrollments
   - Count of drops (if applicable)
   - List of errors

### Step 4: Data Export Pattern (Bidirectional)

For connectors that push data back to external systems:

#### Grade/Assessment Export Workflow

1. **Fetch Assessment Data**
   - Query assessments for specified section
   - Include student info, SLO, outcome, score
   - Filter by date range or finalized status

2. **For Each Assessment:**
   
   a. **Lookup External IDs**
   - Get external section ID from mapping
   - Get external student ID from mapping
   - Skip if either unmapped
   
   b. **Transform Outcome**
   - Map internal outcome scale to external grade scale
   - Example: Exceeded→A, Met→B, Partially Met→C, Not Met→D
   - Include score value if external system supports it
   
   c. **Send to External System**
   - Call external API to post grade
   - Include section ID, student ID, grade, date
   - Handle API rate limits
   - Retry on transient failures
   
   d. **Track Export**
   - Mark assessment as exported with timestamp
   - Store in export tracking table
   - Prevent duplicate exports
   
   e. **Handle Errors**
   - Log export failure with assessment ID
   - Continue processing remaining assessments
   - Store error for admin review

3. **Return Summary**
   - Count of successful exports
   - Count of failures
   - List of errors with assessment IDs

## Advanced Patterns

### Incremental Sync

Only fetch changes since last sync:

1. **Track Last Sync Time**
   - Store timestamp in config or database
   - Pass to external API as filter parameter

2. **Fetch Only Changes**
   - API returns records modified since timestamp
   - Reduces data transfer and processing time

3. **Update Sync Timestamp**
   - After successful sync, store current time
   - Use for next incremental sync

### Batch Operations

Process records in batches for performance:

1. **Chunk Data**
   - Split large result sets into chunks (e.g., 100 records)
   - Process one chunk at a time

2. **Use Transactions**
   - Begin transaction
   - Process entire chunk
   - Commit if all succeed, rollback on error
   - Maintains data consistency

3. **Progress Tracking**
   - Report progress after each batch
   - Allow resumption if interrupted

### Conflict Resolution

Handle conflicts when data differs between systems:

**Strategies:**

1. **External System Wins** (default for connectors)
   - External system is source of truth
   - Overwrite internal data with external values
   - Simple, predictable

2. **Last-Write-Wins**
   - Compare timestamps
   - Keep most recently modified version
   - Requires reliable timestamps

3. **Manual Resolution**
   - Flag conflicts for admin review
   - Store both versions
   - Provide UI for manual decision

4. **Field-Level Merge**
   - External system owns some fields (name, ID)
   - Internal system owns others (notes, assessments)
   - Never overwrite internal-only fields

### Error Recovery

Robust error handling:

1. **Identify Error Type**
   - Network errors: Retry with backoff
   - Authentication errors: Alert admin, stop sync
   - Validation errors: Log and skip record
   - Database errors: Rollback and alert

2. **Retry Logic**
   - Retry transient errors (network, timeout)
   - Exponential backoff (1s, 2s, 4s, 8s)
   - Maximum retry attempts (e.g., 3)
   - Don't retry permanent errors (4xx codes)

3. **Logging**
   - Log all sync operations
   - Include timestamp, entity, operation, result
   - Store errors with full context
   - Provide admin interface to view logs

4. **Admin Recovery Actions**
   - Retry failed records individually
   - Clear error status after manual fix
   - Re-run full sync if needed

## Scheduling and Automation

### Manual Trigger

Provide admin UI to trigger sync:
- Button to start import/export
- Display progress in real-time
- Show summary when complete
- Allow cancellation

### Scheduled Sync

Run connector on schedule:

1. **Cron Integration**
   - Define cron expression (daily at 2am, hourly, etc.)
   - System scheduler calls connector method
   - Runs in background

2. **Locking**
   - Check if sync already running
   - Prevent concurrent syncs
   - Use database lock or lock file

3. **Notification**
   - Email admin on errors
   - Alert if sync fails repeatedly
   - Send summary reports

### Webhook Integration

Real-time sync triggered by external system:

1. **Endpoint Setup**
   - Create webhook receiver route
   - Validate webhook signature
   - Parse webhook payload

2. **Event Processing**
   - Determine event type (student created, enrollment changed, etc.)
   - Extract relevant data
   - Trigger appropriate sync operation
   - Return success response quickly

3. **Security**
   - Verify webhook signature
   - Validate IP whitelist
   - Rate limit webhook calls

## Testing Data Connectors

### Unit Tests

Test individual methods:
- Test ID mapping functions
- Test data transformation
- Test error handling
- Mock external API calls

### Integration Tests

Test full sync workflows:
- Test with sample external data
- Verify mappings created correctly
- Verify core data populated
- Test conflict resolution

### External System Testing

Test against real external system:
- Use sandbox/test environment
- Test with production-like data
- Verify API error handling
- Test rate limits and timeouts

## Common Connector Scenarios

### SIS Connector

**Import from SIS:**
- Students and demographic data
- Course catalog
- Sections/offerings
- Enrollment rosters
- Instructor assignments

**Export to SIS:** (rare)
- Assessment results as grades
- Student notes/flags

### LMS Connector

**Import from LMS:**
- Assignment grades
- Rubric-based assessments
- Student submissions
- Engagement metrics

**Export to LMS:**
- SLO assessment results
- Aggregated program outcomes

### Data Warehouse Connector

**Export to warehouse:**
- Anonymized assessment data
- Aggregated statistics
- Historical trends
- Institutional outcomes

**No import** (warehouse is read-only destination)

## Best Practices Summary

### Core Principles

**DO:**
- ✅ Use core models for all core data operations
- ✅ Maintain separate mapping tables
- ✅ Make sync operations idempotent
- ✅ Log all operations and errors
- ✅ Validate external data before import
- ✅ Handle errors gracefully
- ✅ Provide admin UI for management

**DON'T:**
- ❌ Modify core database schema
- ❌ Write directly to core tables with SQL
- ❌ Store external credentials in code
- ❌ Assume external API always works
- ❌ Sync data deleted from external system automatically
- ❌ Break core functionality if connector fails

### Performance Optimization

- Use batch operations for large datasets
- Implement incremental sync
- Cache external API responses
- Use database indexes on mapping tables
- Process asynchronously for large operations
- Report progress for long-running syncs

### Security Considerations

- Store credentials in environment variables
- Use HTTPS for all API calls
- Validate and sanitize external data
- Audit all data imports
- Rate limit sync operations
- Restrict admin interface to authorized users

### Maintenance

- Monitor sync success/failure rates
- Alert on repeated failures
- Provide sync history/logs
- Document external API dependencies
- Update connector when external API changes
- Test regularly against production API
