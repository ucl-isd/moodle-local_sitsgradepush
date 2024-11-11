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
    And the "mod" "assign1" is mapped to "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Remove source for a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "Assign 1" is mapped to "72hr take-home examination (3000 words)"
    And I click on the "Remove source" button for "72hr take-home examination (3000 words)"
    And I click on "Confirm" "button" in the "Remove source" "dialogue"
    Then I should see "72hr take-home examination (3000 words)" is not mapped
