@local @local_sitsgradepush

Feature: Marks transfer from Moodle to SITS for re-assessment
  In order to transfer marks from Moodle to SITS for re-assessment
  As a teacher with the appropriate permissions
  I need to go to the SITS Marks Transfer dashboard page, switch to the re-assessment page and transfer marks

  Background:
    Given the following config values are set as admin:
      | reassessment_enabled | 1 | local_sitsgradepush |
    And the following "users" exist:
      | username | firstname | lastname | idnumber | email                |
      | student1 | Student1  | Test     | 23456781 | student1@example.com |
      | student2 | Student2  | Test     | 23456782 | student2@example.com |
      | student3 | Student3  | Test     | 23456783 | student3@example.com |
      | teacher1 | Teacher1  | Test     | tea1     | teacher1@example.com |
    And the following custom field exists:
      | category  | CLC |
      | shortname | course_year |
      | name      | Course Year |
      | type      | text        |
    And the following "courses" exist:
      | fullname | shortname | format | customfield_course_year |
      | Course 1 | C1        | topics | ##now##%Y##             |
    And the following "course enrolments" exist:
      | user     | course | role |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
      | teacher1 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | local/sitsgradepush:mapassessment | Allow      | editingteacher | Course       | C1        |
      | local/sitsgradepush:pushgrade     | Allow      | editingteacher | Course       | C1        |
    And the course "C1" is set up for marks transfer

  @javascript
  Scenario: Transfer marks for an re-assessment assignment to SITS
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       |
    And the following "mod_assign > submissions" exist:
      | assign  | user     | onlinetext                           |
      | assign1 | student1 | This is a submission for assignment  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Assign 1   | student1 | 50    |
    And the "mod" "assign1" is a re-assessment and mapped to "Coursework 4000 word written case studies"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I should see "1" marks to transfer for "Coursework 4000 word written case studies"
    And I click on the "Transfer marks" button for "Coursework 4000 word written case studies"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "Coursework 4000 word written case studies"

  @javascript
  Scenario: Transfer marks for a re-assessment quiz to SITS
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | quiz            | Quiz 1     | Q1 desc | C1     | quiz1       | 1       |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name  | questiontext         |
      | Test questions   | description | Intro | Welcome to this quiz |
      | Test questions   | truefalse   | TF1   | First question       |
      | Test questions   | truefalse   | TF2   | Second question      |
    And quiz "Quiz 1" contains the following questions:
      | question | page | maxmark |
      | Intro    | 1    |         |
      | TF1      | 1    | 50.0    |
      | TF2      | 1    | 50.0    |
    And user "student1" has attempted "Quiz 1" with responses:
      | slot | response |
      |   2  | True     |
      |   3  | False    |
    And user "student2" has attempted "Quiz 1" with responses:
      | slot | response |
      |   2  | True     |
      |   3  | True     |
    And the "mod" "quiz1" is a re-assessment and mapped to "Coursework 4000 word written case studies"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I should see "2" marks to transfer for "Coursework 4000 word written case studies"
    And I click on the "Transfer marks" button for "Coursework 4000 word written case studies"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "Coursework 4000 word written case studies"

  @javascript
  Scenario: Transfer marks for a re-assessment grade item to SITS
    Given the following "grade items" exist:
      | itemname          | course | idnumber   |
      | Grade Item 1      | C1     | gradeitem1 |
    And the following "grade grades" exist:
      | gradeitem    | user     | grade |
      | Grade Item 1 | student1 | 21.00 |
      | Grade Item 1 | student2 | 50.00 |
    And the course "C1" is regraded
    And the "gradeitem" "gradeitem1" is a re-assessment and mapped to "Coursework 4000 word written case studies"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I should see "2" marks to transfer for "Coursework 4000 word written case studies"
    And I click on the "Transfer marks" button for "Coursework 4000 word written case studies"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "Coursework 4000 word written case studies"

  @javascript
  Scenario: Transfer marks for a re-assessment grade category to SITS
    Given the following "grade categories" exist:
      | fullname         | course |
      | Grade category 1 | C1     |
    And the following "grade items" exist:
      | itemname     | course | gradecategory    |
      | Grade Item 2 | C1     | Grade category 1 |
    And the following "grade grades" exist:
      | gradeitem    | user     | grade |
      | Grade Item 2 | student1 | 36.00 |
      | Grade Item 2 | student2 | 72.00 |
    And the course "C1" is regraded
    And the "gradecategory" "Grade category 1" is a re-assessment and mapped to "Coursework 4000 word written case studies"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the marks transfer types dropdown menu and select "Re-assessment"
    And I should see "Re-assessment" in the "tertiary-navigation" "region"
    And I should see "2" marks to transfer for "Coursework 4000 word written case studies"
    And I click on the "Transfer marks" button for "Coursework 4000 word written case studies"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "Coursework 4000 word written case studies"
