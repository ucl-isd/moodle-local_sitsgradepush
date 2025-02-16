@local @local_sitsgradepush

Feature: Remove Moodle source for SITS assessment component
  As a teacher with the appropriate permissions
  I can remove a Moodle source for a SITS assessment component

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | idnumber | email                |
      | teacher1 | Teacher1  | Test     | tea1     | teacher1@example.com |
    And the following custom field exists for grade push:
      | category  | CLC |
      | shortname | course_year |
      | name      | Course Year |
      | type      | text        |
    And the following "courses" exist:
      | fullname | shortname | format | customfield_course_year |
      | Course 1 | C1        | topics | ##now##%Y##             |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | local/sitsgradepush:mapassessment | Allow      | editingteacher | Course       | C1        |
    And the course "C1" is set up for marks transfer
    And the course "C1" is regraded

  @javascript
  Scenario: Remove source for a SITS assessment component
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       |
    And the "mod_assign" "assign1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Assign 1" is mapped to "72hr take-home examination (3000 words)"
    And I click on the "Remove source" button for "72hr take-home examination (3000 words)"
    And I click on "Confirm" "button" in the "Remove source" "dialogue"
    Then I should see "72hr take-home examination (3000 words)" is not mapped

  @javascript
  Scenario: Change source and remove source buttons are not available when current time is after the source change cut-off time
  e.g. less than 72 hours before the assignment is due or the quiz is started.
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | allowsubmissionsfromdate | duedate       |
      | assign          | Assign 2   | A2 desc | C1     | assign2     | 1       | ## +1 day ##             | ## +2 days ## |
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | timeopen     | timeclose     |
      | quiz            | Quiz 2     | Q1 desc | C1     | quiz2       | 1       | ## +1 day ## | ## +2 days ## |
    And the following config values are set as admin:
      | extension_enabled         | 1  | local_sitsgradepush |
      | change_source_cutoff_time | 72 | local_sitsgradepush |
    And the "mod_assign" "assign2" is mapped to "72hr take-home examination (3000 words)" with extension
    And the "mod_quiz" "quiz2" is mapped to "2000 word essay" with extension
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Assign 2" is mapped to "72hr take-home examination (3000 words)"
    And I should see "Quiz 2" is mapped to "2000 word essay"
    And I should not see "Change source"
    And I should not see "Remove source"

  @javascript
  Scenario: Change source and remove source buttons are available when current time is before the source change cut-off time
    e.g. more than 72 hours before the assignment is due or the quiz is started.
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | allowsubmissionsfromdate | duedate       |
      | assign          | Assign 3   | A3 desc | C1     | assign3     | 1       | ## +1 day ##            | ## +4 days ## |
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | timeopen     | timeclose     |
      | quiz            | Quiz 3     | Q1 desc | C1     | quiz3       | 1       | ## +4 days ## | ## +5 days ## |
    And the following config values are set as admin:
      | extension_enabled         | 1  | local_sitsgradepush |
      | change_source_cutoff_time | 72 | local_sitsgradepush |
    And the "mod_assign" "assign3" is mapped to "72hr take-home examination (3000 words)" with extension
    And the "mod_quiz" "quiz3" is mapped to "2000 word essay" with extension
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Change source" for "72hr take-home examination (3000 words)"
    And I should see "Remove source" for "72hr take-home examination (3000 words)"
    And I should see "Change source" for "2000 word essay"
    And I should see "Remove source" for "2000 word essay"

  @javascript
  Scenario: Change source and remove source buttons are available when the activity is in the past at the time of mapping
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | allowsubmissionsfromdate | duedate       |
      | assign          | Assign 4   | A4 desc | C1     | assign4     | 1       | ## -4 days ##            | ## -2 days ## |
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | timeopen     | timeclose     |
      | quiz            | Quiz 4     | Q4 desc | C1     | quiz4       | 1       | ## -2 days ## | ## -1 day ## |
    And the following config values are set as admin:
      | extension_enabled         | 1  | local_sitsgradepush |
      | change_source_cutoff_time | 72 | local_sitsgradepush |
    And the "mod_assign" "assign4" is mapped to "72hr take-home examination (3000 words)" with extension
    And the "mod_quiz" "quiz4" is mapped to "2000 word essay" with extension
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Change source" for "72hr take-home examination (3000 words)"
    And I should see "Remove source" for "72hr take-home examination (3000 words)"
    And I should see "Change source" for "2000 word essay"
    And I should see "Remove source" for "2000 word essay"
