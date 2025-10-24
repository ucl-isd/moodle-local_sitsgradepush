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
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       |
      | quiz            | Quiz 1     | Q1 desc | C1     | quiz1       | 1       |
    And the course "C1" is regraded

  @javascript
  Scenario: Remove source for a SITS assessment component
    Given the "mod_assign" "assign1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Assign 1" is mapped to "72hr take-home examination (3000 words)"
    And I click on the "Remove source" button for "72hr take-home examination (3000 words)"
    Then I should see "Are you sure you want to remove source Assign 1 for SITS assessment (001) 72hr take-home examination (3000 words)"
    And I click on "Confirm" "button" in the "Remove source" "dialogue"
    Then I should see "72hr take-home examination (3000 words)" is not mapped

  @javascript
  Scenario: Remove a source that is eligible for extensions
    Given the following config values are set as admin:
      | extension_enabled         | 1  | local_sitsgradepush |
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section | allowsubmissionsfromdate | duedate       |
      | assign          | Assign 2   | A2 desc | C1     | assign2     | 1       | ## +1 day ##             | ## +4 days ## |
    And the "mod_assign" "assign2" is mapped to "72hr take-home examination (3000 words)" with extension
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Assign 2" is mapped to "72hr take-home examination (3000 words)"
    And I click on the "Remove source" button for "72hr take-home examination (3000 words)"
    Then I should see "Are you sure you want to remove source Assign 2 for SITS assessment (001) 72hr take-home examination (3000 words) including automated Reasonable Academic Adjustments (RAAs) extension groups and EC/DAP extension user overrides?"
    And I click on "Confirm" "button" in the "Remove source" "dialogue"
    Then I should see "72hr take-home examination (3000 words)" is not mapped
