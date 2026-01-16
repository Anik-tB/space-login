-- ========================================
-- 1. USER SAFETY PROFILE WITH MULTIPLE JOINS
-- Shows comprehensive user profile with incident stats, group memberships, and course progress
-- ========================================

SELECT
    u.id,
    u.display_name,
    u.email,
    u.phone,
    -- Incident statistics
    COUNT(DISTINCT ir.id) as total_reports,
    SUM(CASE WHEN ir.severity = 'critical' THEN 1 ELSE 0 END) as critical_reports,
    SUM(CASE WHEN ir.status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports,
    -- Emergency contacts
    COUNT(DISTINCT ec.id) as emergency_contacts_count,
    -- Group memberships
    COUNT(DISTINCT gm.id) as group_memberships,
    GROUP_CONCAT(DISTINCT ng.group_name SEPARATOR ', ') as groups_joined,
    -- Course progress
    COUNT(DISTINCT ce.id) as courses_enrolled,
    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as courses_completed,
    COUNT(DISTINCT cert.id) as certificates_earned,
    -- Last activity
    MAX(ir.reported_date) as last_report_date,
    u.last_login
FROM users u
LEFT JOIN incident_reports ir ON u.id = ir.user_id
LEFT JOIN emergency_contacts ec ON u.id = ec.user_id
LEFT JOIN group_members gm ON u.id = gm.user_id
LEFT JOIN neighborhood_groups ng ON gm.group_id = ng.id
LEFT JOIN course_enrollments ce ON u.id = ce.user_id
LEFT JOIN certificates cert ON u.id = cert.user_id
WHERE u.id IN (1, 2, 5, 6, 7)
GROUP BY u.id, u.display_name, u.email, u.phone, u.last_login
ORDER BY total_reports DESC;

-- ========================================
-- 2. INCIDENT HOTSPOT ANALYSIS WITH SUBQUERY
-- Identifies areas with highest incident concentration
-- ========================================

SELECT
    location_name,
    COUNT(*) as incident_count,
    -- Subquery to get percentage of total incidents
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM incident_reports WHERE incident_date >= '2026-01-01'), 2) as percentage_of_total,
    -- Severity breakdown
    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_count,
    SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_count,
    SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_count,
    -- Most common category in this location
    (SELECT category
     FROM incident_reports ir2
     WHERE ir2.location_name = ir.location_name
       AND ir2.incident_date >= '2026-01-01'
     GROUP BY category
     ORDER BY COUNT(*) DESC
     LIMIT 1) as most_common_category,
    -- Average latitude and longitude for mapping
    AVG(latitude) as avg_latitude,
    AVG(longitude) as avg_longitude
FROM incident_reports ir
WHERE incident_date >= '2026-01-01'
  AND location_name IS NOT NULL
  AND location_name != 'Online'
GROUP BY location_name
HAVING incident_count >= 3
ORDER BY incident_count DESC;

-- ========================================
-- 3. USER INCIDENT TIMELINE WITH WINDOW FUNCTIONS
-- Shows incident patterns with running totals and rankings
-- ========================================

SELECT
    user_id,
    u.display_name,
    DATE(incident_date) as incident_day,
    title,
    category,
    severity,
    location_name,
    -- Running total of incidents per user
    ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY incident_date) as incident_number,
    -- Total incidents on same day
    COUNT(*) OVER (PARTITION BY user_id, DATE(incident_date)) as incidents_same_day,
    -- Days since last incident
    DATEDIFF(incident_date,
             LAG(incident_date) OVER (PARTITION BY user_id ORDER BY incident_date)) as days_since_last_incident,
    -- Severity rank within user's reports
    DENSE_RANK() OVER (PARTITION BY user_id ORDER BY
        CASE severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END) as severity_rank
FROM incident_reports ir
JOIN users u ON ir.user_id = u.id
WHERE ir.incident_date >= '2026-01-01'
  AND ir.user_id IN (1, 2, 5, 6, 7)
ORDER BY user_id, incident_date;

-- ========================================
-- 4. COMPLEX CTE: INCIDENT CATEGORY ANALYSIS BY USER
-- Uses Common Table Expression to analyze incident patterns
-- ========================================

WITH UserIncidentStats AS (
    SELECT
        user_id,
        category,
        COUNT(*) as category_count,
        AVG(CASE
            WHEN severity = 'critical' THEN 4
            WHEN severity = 'high' THEN 3
            WHEN severity = 'medium' THEN 2
            WHEN severity = 'low' THEN 1
        END) as avg_severity_score
    FROM incident_reports
    WHERE incident_date >= '2026-01-01'
    GROUP BY user_id, category
),
UserTotals AS (
    SELECT
        user_id,
        SUM(category_count) as total_incidents
    FROM UserIncidentStats
    GROUP BY user_id
)
SELECT
    u.display_name,
    uis.category,
    uis.category_count,
    ROUND(uis.category_count * 100.0 / ut.total_incidents, 2) as percentage_of_user_reports,
    ROUND(uis.avg_severity_score, 2) as avg_severity_score,
    ut.total_incidents as user_total_reports
FROM UserIncidentStats uis
JOIN UserTotals ut ON uis.user_id = ut.user_id
JOIN users u ON uis.user_id = u.id
ORDER BY u.display_name, uis.category_count DESC;

-- ========================================
-- 5. NESTED SUBQUERY: MOST ACTIVE USERS IN EACH CATEGORY
-- Finds top reporter for each incident category
-- ========================================

SELECT
    category,
    display_name as top_reporter,
    report_count,
    total_in_category,
    ROUND(report_count * 100.0 / total_in_category, 2) as percentage_of_category
FROM (
    SELECT
        category,
        user_id,
        COUNT(*) as report_count,
        (SELECT COUNT(*)
         FROM incident_reports ir2
         WHERE ir2.category = ir.category
           AND ir2.incident_date >= '2026-01-01') as total_in_category,
        ROW_NUMBER() OVER (PARTITION BY category ORDER BY COUNT(*) DESC) as rn
    FROM incident_reports ir
    WHERE incident_date >= '2026-01-01'
    GROUP BY category, user_id
) ranked
JOIN users u ON ranked.user_id = u.id
WHERE rn = 1
ORDER BY report_count DESC;

-- ========================================
-- 6. CORRELATED SUBQUERY: USERS WITH ABOVE-AVERAGE INCIDENTS
-- Compares each user's incident count to average
-- ========================================

SELECT
    u.id,
    u.display_name,
    u.email,
    (SELECT COUNT(*)
     FROM incident_reports ir
     WHERE ir.user_id = u.id
       AND ir.incident_date >= '2026-01-01') as user_incidents,
    (SELECT AVG(incident_count)
     FROM (
         SELECT COUNT(*) as incident_count
         FROM incident_reports
         WHERE incident_date >= '2026-01-01'
         GROUP BY user_id
     ) avg_calc) as avg_incidents,
    (SELECT COUNT(*)
     FROM incident_reports ir
     WHERE ir.user_id = u.id
       AND ir.incident_date >= '2026-01-01') -
    (SELECT AVG(incident_count)
     FROM (
         SELECT COUNT(*) as incident_count
         FROM incident_reports
         WHERE incident_date >= '2026-01-01'
         GROUP BY user_id
     ) avg_calc) as difference_from_avg
FROM users u
WHERE u.id IN (1, 2, 5, 6, 7)
HAVING user_incidents > avg_incidents
ORDER BY user_incidents DESC;

-- ========================================
-- 7. MULTI-LEVEL JOIN: INCIDENT RESPONSE CHAIN
-- Shows complete incident handling chain from report to resolution
-- ========================================

SELECT
    ir.id as report_id,
    ir.title,
    u.display_name as reporter,
    ir.category,
    ir.severity,
    ir.status,
    ir.incident_date,
    ir.reported_date,
    -- Legal consultation info
    lc.subject as legal_consultation,
    lap.organization_name as legal_provider,
    lc.status as consultation_status,
    -- Medical support info
    sr.referral_type,
    msp.provider_name as medical_provider,
    sr.status as referral_status,
    -- Dispute info
    d.reason as dispute_reason,
    d.status as dispute_status,
    -- Notifications
    COUNT(DISTINCT n.id) as notifications_sent,
    -- Related alerts
    COUNT(DISTINCT a.id) as related_alerts
FROM incident_reports ir
JOIN users u ON ir.user_id = u.id
LEFT JOIN legal_consultations lc ON ir.id = lc.report_id
LEFT JOIN legal_aid_providers lap ON lc.provider_id = lap.id
LEFT JOIN support_referrals sr ON ir.id = sr.report_id
LEFT JOIN medical_support_providers msp ON sr.provider_id = msp.id
LEFT JOIN disputes d ON ir.id = d.report_id
LEFT JOIN notifications n ON ir.user_id = n.user_id
    AND n.created_at >= ir.reported_date
LEFT JOIN alerts a ON a.related_report_id = ir.id
WHERE ir.incident_date >= '2026-01-01'
  AND ir.user_id IN (1, 2, 5, 6, 7)
GROUP BY ir.id, ir.title, u.display_name, ir.category, ir.severity, ir.status,
         ir.incident_date, ir.reported_date, lc.subject, lap.organization_name,
         lc.status, sr.referral_type, msp.provider_name, sr.status,
         d.reason, d.status
ORDER BY ir.incident_date DESC;

-- ========================================
-- 8. RECURSIVE CTE: GROUP MEMBER ACTIVITY HIERARCHY
-- Shows group activity with member contributions
-- ========================================

WITH RECURSIVE GroupHierarchy AS (
    -- Base case: Groups with their basic info
    SELECT
        ng.id as group_id,
        ng.group_name,
        ng.created_by,
        ng.member_count,
        0 as level
    FROM neighborhood_groups ng
    WHERE ng.status = 'active'
),
MemberActivity AS (
    SELECT
        gm.group_id,
        gm.user_id,
        u.display_name,
        gm.role,
        gm.contribution_score,
        COUNT(DISTINCT ga.id) as alerts_posted,
        COUNT(DISTINCT ir.id) as incidents_reported
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    LEFT JOIN group_alerts ga ON ga.group_id = gm.group_id AND ga.posted_by = gm.user_id
    LEFT JOIN incident_reports ir ON ir.user_id = gm.user_id AND ir.incident_date >= '2026-01-01'
    WHERE gm.status = 'active'
    GROUP BY gm.group_id, gm.user_id, u.display_name, gm.role, gm.contribution_score
)
SELECT
    gh.group_name,
    gh.member_count,
    ma.display_name as member_name,
    ma.role,
    ma.contribution_score,
    ma.alerts_posted,
    ma.incidents_reported,
    ma.contribution_score + (ma.alerts_posted * 5) + (ma.incidents_reported * 3) as total_activity_score
FROM GroupHierarchy gh
JOIN MemberActivity ma ON gh.group_id = ma.group_id
ORDER BY gh.group_name, total_activity_score DESC;

-- ========================================
-- 9. COMPLEX AGGREGATION: DAILY INCIDENT TRENDS
-- Analyzes incident patterns by day with comparisons
-- ========================================

SELECT
    DATE(incident_date) as incident_day,
    DAYNAME(incident_date) as day_name,
    COUNT(*) as total_incidents,
    -- Severity breakdown
    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
    SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
    SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low,
    -- Category breakdown
    COUNT(DISTINCT category) as unique_categories,
    COUNT(DISTINCT location_name) as unique_locations,
    COUNT(DISTINCT user_id) as unique_reporters,
    -- Compare to previous day
    LAG(COUNT(*)) OVER (ORDER BY DATE(incident_date)) as previous_day_count,
    COUNT(*) - LAG(COUNT(*)) OVER (ORDER BY DATE(incident_date)) as day_over_day_change,
    -- Running average
    AVG(COUNT(*)) OVER (ORDER BY DATE(incident_date) ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) as three_day_avg
FROM incident_reports
WHERE incident_date >= '2026-01-01' AND incident_date < '2026-01-17'
GROUP BY DATE(incident_date), DAYNAME(incident_date)
ORDER BY incident_day;

-- ========================================
-- 10. ADVANCED JOIN WITH CASE: USER SAFETY SCORE CALCULATION
-- Calculates comprehensive safety score based on multiple factors
-- ========================================

SELECT
    u.id,
    u.display_name,
    u.email,
    -- Incident metrics
    COUNT(DISTINCT ir.id) as total_reports,
    SUM(CASE WHEN ir.severity = 'critical' THEN 10
             WHEN ir.severity = 'high' THEN 7
             WHEN ir.severity = 'medium' THEN 4
             WHEN ir.severity = 'low' THEN 2
             ELSE 0 END) as incident_severity_score,
    -- Emergency preparedness
    COUNT(DISTINCT ec.id) * 10 as emergency_contacts_score,
    -- Community engagement
    COUNT(DISTINCT gm.id) * 15 as group_membership_score,
    COUNT(DISTINCT ga.id) * 5 as group_alerts_score,
    -- Education
    SUM(CASE WHEN ce.status = 'completed' THEN 20
             WHEN ce.status = 'in_progress' THEN 10
             ELSE 0 END) as education_score,
    COUNT(DISTINCT cert.id) * 25 as certificate_score,
    -- Support utilization
    COUNT(DISTINCT lc.id) * 8 as legal_consultation_score,
    COUNT(DISTINCT sr.id) * 8 as support_referral_score,
    -- Calculate total safety awareness score
    (COUNT(DISTINCT ec.id) * 10) +
    (COUNT(DISTINCT gm.id) * 15) +
    (COUNT(DISTINCT ga.id) * 5) +
    (SUM(CASE WHEN ce.status = 'completed' THEN 20
              WHEN ce.status = 'in_progress' THEN 10
              ELSE 0 END)) +
    (COUNT(DISTINCT cert.id) * 25) +
    (COUNT(DISTINCT lc.id) * 8) +
    (COUNT(DISTINCT sr.id) * 8) -
    (SUM(CASE WHEN ir.severity = 'critical' THEN 10
              WHEN ir.severity = 'high' THEN 7
              WHEN ir.severity = 'medium' THEN 4
              WHEN ir.severity = 'low' THEN 2
              ELSE 0 END)) as total_safety_score,
    -- Risk level classification
    CASE
        WHEN COUNT(DISTINCT ir.id) >= 8 AND SUM(CASE WHEN ir.severity IN ('critical', 'high') THEN 1 ELSE 0 END) >= 5 THEN 'High Risk'
        WHEN COUNT(DISTINCT ir.id) >= 5 THEN 'Medium Risk'
        WHEN COUNT(DISTINCT ir.id) >= 2 THEN 'Low Risk'
        ELSE 'Minimal Risk'
    END as risk_classification
FROM users u
LEFT JOIN incident_reports ir ON u.id = ir.user_id AND ir.incident_date >= '2026-01-01'
LEFT JOIN emergency_contacts ec ON u.id = ec.user_id
LEFT JOIN group_members gm ON u.id = gm.user_id
LEFT JOIN group_alerts ga ON u.id = ga.posted_by AND ga.created_at >= '2026-01-01'
LEFT JOIN course_enrollments ce ON u.id = ce.user_id
LEFT JOIN certificates cert ON u.id = cert.user_id
LEFT JOIN legal_consultations lc ON u.id = lc.user_id
LEFT JOIN support_referrals sr ON u.id = sr.user_id
WHERE u.id IN (1, 2, 5, 6, 7)
GROUP BY u.id, u.display_name, u.email
ORDER BY total_safety_score DESC;

-- ========================================
-- 11. LOCATION-BASED ANALYSIS WITH SPATIAL JOINS
-- Analyzes incidents by location with distance calculations
-- ========================================

SELECT
    ir1.location_name as primary_location,
    COUNT(DISTINCT ir1.id) as incidents_at_location,
    AVG(ir1.latitude) as avg_lat,
    AVG(ir1.longitude) as avg_lng,
    -- Find nearby incidents (within ~1km)
    (SELECT COUNT(DISTINCT ir2.id)
     FROM incident_reports ir2
     WHERE ir2.location_name != ir1.location_name
       AND ir2.latitude IS NOT NULL
       AND ir2.longitude IS NOT NULL
       AND ir2.incident_date >= '2026-01-01'
       AND ABS(ir2.latitude - AVG(ir1.latitude)) < 0.01
       AND ABS(ir2.longitude - AVG(ir1.longitude)) < 0.01) as nearby_incidents,
    -- Most common category at this location
    (SELECT category
     FROM incident_reports ir3
     WHERE ir3.location_name = ir1.location_name
       AND ir3.incident_date >= '2026-01-01'
     GROUP BY category
     ORDER BY COUNT(*) DESC
     LIMIT 1) as primary_threat,
    -- Time pattern
    CASE
        WHEN AVG(HOUR(ir1.incident_date)) BETWEEN 6 AND 12 THEN 'Morning (6AM-12PM)'
        WHEN AVG(HOUR(ir1.incident_date)) BETWEEN 12 AND 18 THEN 'Afternoon (12PM-6PM)'
        WHEN AVG(HOUR(ir1.incident_date)) BETWEEN 18 AND 24 THEN 'Evening (6PM-12AM)'
        ELSE 'Night (12AM-6AM)'
    END as peak_time_period
FROM incident_reports ir1
WHERE ir1.incident_date >= '2026-01-01'
  AND ir1.location_name IS NOT NULL
  AND ir1.location_name != 'Online'
  AND ir1.latitude IS NOT NULL
GROUP BY ir1.location_name
HAVING incidents_at_location >= 2
ORDER BY incidents_at_location DESC;

-- ========================================
-- 12. PIVOT-STYLE QUERY: INCIDENT MATRIX BY USER AND CATEGORY
-- Shows incident distribution across users and categories
-- ========================================

SELECT
    u.display_name,
    SUM(CASE WHEN ir.category = 'harassment' THEN 1 ELSE 0 END) as harassment,
    SUM(CASE WHEN ir.category = 'assault' THEN 1 ELSE 0 END) as assault,
    SUM(CASE WHEN ir.category = 'theft' THEN 1 ELSE 0 END) as theft,
    SUM(CASE WHEN ir.category = 'stalking' THEN 1 ELSE 0 END) as stalking,
    SUM(CASE WHEN ir.category = 'cyberbullying' THEN 1 ELSE 0 END) as cyberbullying,
    SUM(CASE WHEN ir.category = 'vandalism' THEN 1 ELSE 0 END) as vandalism,
    SUM(CASE WHEN ir.category = 'discrimination' THEN 1 ELSE 0 END) as discrimination,
    SUM(CASE WHEN ir.category = 'other' THEN 1 ELSE 0 END) as other,
    COUNT(*) as total_incidents,
    -- Most affected category per user
    (SELECT category
     FROM incident_reports ir2
     WHERE ir2.user_id = u.id
       AND ir2.incident_date >= '2026-01-01'
     GROUP BY category
     ORDER BY COUNT(*) DESC
     LIMIT 1) as primary_concern
FROM users u
LEFT JOIN incident_reports ir ON u.id = ir.user_id AND ir.incident_date >= '2026-01-01'
WHERE u.id IN (1, 2, 5, 6, 7)
GROUP BY u.id, u.display_name
ORDER BY total_incidents DESC;

-- ========================================
-- 13. TIME-SERIES ANALYSIS: INCIDENT ESCALATION PATTERNS
-- Identifies users with escalating incident severity
-- ========================================

WITH IncidentSequence AS (
    SELECT
        user_id,
        incident_date,
        severity,
        CASE severity
            WHEN 'critical' THEN 4
            WHEN 'high' THEN 3
            WHEN 'medium' THEN 2
            WHEN 'low' THEN 1
        END as severity_score,
        LAG(CASE severity
            WHEN 'critical' THEN 4
            WHEN 'high' THEN 3
            WHEN 'medium' THEN 2
            WHEN 'low' THEN 1
        END) OVER (PARTITION BY user_id ORDER BY incident_date) as prev_severity_score,
        ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY incident_date) as incident_seq
    FROM incident_reports
    WHERE incident_date >= '2026-01-01'
)
SELECT
    u.display_name,
    COUNT(*) as total_incidents,
    SUM(CASE WHEN severity_score > prev_severity_score THEN 1 ELSE 0 END) as escalations,
    SUM(CASE WHEN severity_score < prev_severity_score THEN 1 ELSE 0 END) as de_escalations,
    ROUND(SUM(CASE WHEN severity_score > prev_severity_score THEN 1 ELSE 0 END) * 100.0 /
          NULLIF(COUNT(*) - 1, 0), 2) as escalation_rate,
    MAX(severity_score) as max_severity_reached,
    AVG(severity_score) as avg_severity
FROM IncidentSequence iseq
JOIN users u ON iseq.user_id = u.id
WHERE iseq.user_id IN (1, 2, 5, 6, 7)
GROUP BY u.id, u.display_name
ORDER BY escalation_rate DESC;

-- ========================================
-- 14. COMPREHENSIVE DASHBOARD QUERY
-- Single query for complete system overview
-- ========================================

SELECT
    'System Overview' as metric_category,
    'Total Active Users' as metric_name,
    COUNT(DISTINCT u.id) as metric_value
FROM users u
WHERE u.id IN (1, 2, 5, 6, 7)

UNION ALL

SELECT
    'Incidents',
    'Total Reports (Jan 2026)',
    COUNT(*)
FROM incident_reports
WHERE incident_date >= '2026-01-01'

UNION ALL

SELECT
    'Incidents',
    'Critical Severity',
    COUNT(*)
FROM incident_reports
WHERE incident_date >= '2026-01-01' AND severity = 'critical'

UNION ALL

SELECT
    'Incidents',
    'Under Investigation',
    COUNT(*)
FROM incident_reports
WHERE incident_date >= '2026-01-01' AND status = 'investigating'

UNION ALL

SELECT
    'Emergency',
    'Panic Alerts Triggered',
    COUNT(*)
FROM panic_alerts
WHERE triggered_at >= '2026-01-01'

UNION ALL

SELECT
    'Emergency',
    'Emergency Contacts Registered',
    COUNT(*)
FROM emergency_contacts

UNION ALL

SELECT
    'Community',
    'Active Group Members',
    COUNT(*)
FROM group_members
WHERE status = 'active' AND user_id IN (1, 2, 5, 6, 7)

UNION ALL

SELECT
    'Community',
    'Group Alerts Posted',
    COUNT(*)
FROM group_alerts
WHERE created_at >= '2026-01-01'

UNION ALL

SELECT
    'Education',
    'Course Enrollments',
    COUNT(*)
FROM course_enrollments
WHERE user_id IN (1, 2, 5, 6, 7)

UNION ALL

SELECT
    'Education',
    'Certificates Earned',
    COUNT(*)
FROM certificates
WHERE user_id IN (1, 2, 5, 6, 7)

UNION ALL

SELECT
    'Support',
    'Legal Consultations',
    COUNT(*)
FROM legal_consultations
WHERE user_id IN (1, 2, 5, 6, 7)

UNION ALL

SELECT
    'Support',
    'Medical Referrals',
    COUNT(*)
FROM support_referrals
WHERE user_id IN (1, 2, 5, 6, 7)

ORDER BY metric_category, metric_name;

