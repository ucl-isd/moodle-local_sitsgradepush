@local @local_sitsgradepush

Feature: Map Moodle source to SITS assessment component for re-assessment
  In order to transfer marks from Moodle to SITS for re-assessment
  As a teacher with the appropriate permissions
  I need to map a Moodle source to a SITS assessment component on the re-assessment dashboard page

  Background:
    Given the following config values are set as admin:
      | reassessment_enabled | 1 | local_sitsgradepush |
    And the following "users" exist:
      | username | firstname | lastname | idnumber | email                |
      | student1 | Student1  | Test     | 23456781 | student1@example.com |
      | student2 | Student2  | Test     | 23456782 | student2@example.com |
      | student3 | Student3  | Test     | 23456783 | student3@example.com |
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
      | student1 | C1 | student |
      | student3 | C1 | student |
      | teacher1 | C1 | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | local/sitsgradepush:mapassessment | Allow      | editingteacher | Course       | C1        |
    And the course "C1" is set up for marks transfer
    And the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       |
      | quiz            | Quiz 1     | Q1 desc | C1     | quiz1       | 1       |
    And the following "grade items" exist:
      | itemname          | course | idnumber   |
      | Grade Item 1      | C1     | gradeitem1 |
    And the following "grade categories" exist:
      | fullname | course |
      | Grade category 1 | C1 |
    And the following "grade items" exist:
      | itemname    | course | gradecategory |
      | Grade Item 2 | C1 | Grade category 1 |
    And the course "C1" is regraded

  @javascript
  Scenario: Map an re-assessment assignment to a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I click on the "Select source" button for "Coursework 4000 word written case studies"
    And I click on the "Select" button for "Assign 1"
    And I press "Confirm"
    Then I should see "Assign 1" is mapped to "Coursework 4000 word written case studies"

  @javascript
  Scenario: Map a re-assessment quiz to a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I click on the "Select source" button for "Coursework 4000 word written case studies"
    And I click on the "Select" button for "Quiz 1"
    And I press "Confirm"
    Then I should see "Quiz 1" is mapped to "Coursework 4000 word written case studies"

  @javascript
  Scenario: Map a re-assessment grade item to a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I click on the "Select source" button for "Coursework 4000 word written case studies"
    And I click on the "Select" button for "Grade Item 1"
    And I press "Confirm"
    Then I should see "Grade Item 1" is mapped to "Coursework 4000 word written case studies"

  @javascript
  Scenario: Map a re-assessment grade category to a SITS assessment component
    Given I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I click on the "Select source" button for "Coursework 4000 word written case studies"
    And I click on the "Select" button for "Grade category 1"
    And I press "Confirm"
    Then I should see "Grade category 1" is mapped to "Coursework 4000 word written case studies"
