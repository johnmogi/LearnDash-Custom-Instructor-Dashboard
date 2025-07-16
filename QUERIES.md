# LearnDash Custom Dashboard - Database Queries

This document contains useful SQL queries for the LearnDash Custom Dashboard plugin.

## User and Group Management

### 1. List All Teachers and Their Groups
```sql
SELECT u.ID, u.user_login, u.display_name, u.user_email, 
       g.ID as group_id, g.post_title as group_name
FROM edc_users u
JOIN edc_usermeta um ON u.ID = um.user_id
JOIN edc_posts g ON g.ID = SUBSTRING_INDEX(um.meta_key, '_', -1)
WHERE um.meta_key LIKE 'learndash_group_leaders_%'
   OR um.meta_key LIKE 'learndash_group_users_%'
ORDER BY u.display_name, g.post_title;
```

### 2. Get All Students in a Specific Group
```sql
SELECT u.ID, u.user_login, u.display_name, u.user_email
FROM edc_users u
JOIN edc_usermeta um ON u.ID = um.user_id
WHERE um.meta_key = 'learndash_group_users_9805'  -- Replace 9805 with actual group ID
ORDER BY u.display_name;
```

## Course and Quiz Activity

### 3. Get Recent Quiz Attempts
```sql
SELECT 
    u.display_name as student_name,
    q.post_title as quiz_name,
    c.post_title as course_name,
    FROM_UNIXTIME(ua.activity_completed) as completion_date,
    ua.activity_status as status
FROM edc_learndash_user_activity ua
JOIN edc_users u ON ua.user_id = u.ID
JOIN edc_posts q ON ua.post_id = q.ID
LEFT JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'quiz'
ORDER BY ua.activity_completed DESC
LIMIT 20;
```

### 4. Get Course Completion Status
```sql
SELECT 
    u.display_name as student_name,
    c.post_title as course_name,
    FROM_UNIXTIME(ua.activity_completed) as completion_date,
    CASE 
        WHEN ua.activity_status = 1 THEN 'Completed'
        WHEN ua.activity_status = 0 THEN 'In Progress'
        ELSE 'Not Started'
    END as status
FROM edc_learndash_user_activity ua
JOIN edc_users u ON ua.user_id = u.ID
JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'course'
ORDER BY ua.activity_completed DESC;
```

## Quiz Statistics

### 5. Get Quiz Attempts with Scores
```sql
SELECT 
    u.display_name as student_name,
    q.post_title as quiz_name,
    FROM_UNIXTIME(ua.activity_completed) as completion_date,
    ua.activity_meta as score_data
FROM edc_learndash_user_activity ua
JOIN edc_users u ON ua.user_id = u.ID
JOIN edc_posts q ON ua.post_id = q.ID
WHERE ua.activity_type = 'quiz'
ORDER BY ua.activity_completed DESC
LIMIT 10;
```

## Group Management

### 6. Get All Groups with User Counts
```sql
SELECT 
    g.ID as group_id,
    g.post_title as group_name,
    COUNT(DISTINCT um.user_id) as member_count
FROM edc_posts g
LEFT JOIN edc_usermeta um ON um.meta_key = CONCAT('learndash_group_users_', g.ID)
WHERE g.post_type = 'groups'
GROUP BY g.ID, g.post_title
ORDER BY g.post_title;
```

## User Progress

### 7. Get User's Course Progress
```sql
SELECT 
    c.post_title as course_name,
    FROM_UNIXTIME(ua.activity_completed) as completion_date,
    CASE 
        WHEN ua.activity_status = 1 THEN 'Completed'
        WHEN ua.activity_status = 0 THEN 'In Progress'
        ELSE 'Not Started'
    END as status
FROM edc_learndash_user_activity ua
JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'course'
AND ua.user_id = 14  -- Replace with actual user ID
ORDER BY ua.activity_completed DESC;
```

## Notes:
- Table prefix: `edc_` (adjust if different in your installation)
- All timestamps are in UNIX format, use `FROM_UNIXTIME()` to convert to readable dates
- Replace hardcoded IDs (like user_id, group_id) with actual values when running queries
