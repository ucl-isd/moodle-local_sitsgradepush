@local @local_sitsgradepush

Feature: Extensions message is displayed to indicate extensions eligibility
  As a teacher I want to see a message indicating that extensions are eligible for a SITS assessment component or mapping

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
      | activity        | name       | intro   | course | idnumber    | section | allowsubmissionsfromdate | duedate       |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       | ## +1 day ##             | ## +4 days ## |
    And the following config values are set as admin:
      | extension_enabled         | 1  | local_sitsgradepush |

  @javascript
  Scenario: Extensions message is displayed to indicate extensions eligibility for a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    Then I should see "SoRA extensions can be automatically applied to this assessment once mapped. A scheduled task will run to check for any updates on SITS every hour." in the table row containing "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Extensions message is displayed to indicate extensions are applied to the mapped moodle source
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the "Select source" button for "72hr take-home examination (3000 words)"
    And I click on the "Select" button for "Assign 1"
    And I set the field "import-sora" to "1"
    And I press "Confirm"
    Then I should see "SoRA extensions are automatically applied to this assessment. A scheduled task will run to check for any updates on SITS every hour." in the table row containing "72hr take-home examination (3000 words)"

  @javascript
  Scenario: User chose to disable the extensions
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the "Select source" button for "72hr take-home examination (3000 words)"
    And I click on the "Select" button for "Assign 1"
    And I set the field "import-sora" to "0"
    And I press "Confirm"
    Then I should not see "SoRA extensions are automatically applied to this assessment. A scheduled task will run to check for any updates on SITS every hour." in the table row containing "72hr take-home examination (3000 words)"
