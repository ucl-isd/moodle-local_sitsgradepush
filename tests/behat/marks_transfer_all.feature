@local @local_sitsgradepush

Feature: Transfer all mapped sources from Moodle to SITS
  In order to transfer all mapped sources from Moodle to SITS
  As a teacher with the appropriate permissions
  I need to go to the SITS Marks Transfer dashboard page and transfer all marks

  Background:
    Given the following "users" exist:
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
      | Course 1 | C1        | topics | ##now##%Y##         |
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
  Scenario: Transfer all marks to SITS
    Given the following "activities" exist:
      | activity        | name       | intro   | course | idnumber    | section |
      | assign          | Assign 1   | A1 desc | C1     | assign1     | 1       |
    And the following "mod_assign > submissions" exist:
      | assign  | user     | onlinetext                           |
      | assign1 | student1 | This is a submission for assignment  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Assign 1   | student1 | 50    |
    And the "mod" "assign1" is mapped to "72hr take-home examination (3000 words)"
    And the following "grade items" exist:
      | itemname          | course | idnumber   |
      | Grade Item 1      | C1     | gradeitem1 |
    And the following "grade grades" exist:
      | gradeitem    | user     | grade |
      | Grade Item 1 | student1 | 21.00 |
      | Grade Item 1 | student2 | 50.00 |
    And the course "C1" is regraded
    And the "gradeitem" "gradeitem1" is mapped to "2000 word essay"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "1" marks to transfer for "72hr take-home examination (3000 words)"
    And I should see "2" marks to transfer for "2000 word essay"
    And I click on "Transfer All" "button"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"
    Then I should see "0" marks to transfer for "2000 word essay"
