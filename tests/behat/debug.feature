@local @local_sitsgradepush

Feature: Debugging

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | idnumber | email                |
      | student1 | Student1  | Test     | 23456781 | student1@example.com |
      | student2 | Student2  | Test     | 23456782 | student2@example.com |
      | student3 | Student3  | Test     | 23456783 | student3@example.com |
      | teacher1 | Teacher1  | Test     | tea1     | teacher1@example.com |
    And the following custom field exists for grade push:
      | category  | CLC         |
      | shortname | course_year |
      | name      | Course Year |
      | type      | text        |
    And the following "courses" exist:
      | fullname | shortname | format | customfield_course_year |
      | Course 1 | C1        | topics | ##now##%Y##             |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | local/sitsgradepush:mapassessment | Allow      | editingteacher | Course       | C1        |
      | local/sitsgradepush:pushgrade     | Allow      | editingteacher | Course       | C1        |
    And the course "C1" is set up for marks transfer

  @javascript
  Scenario: Transfer marks for an assignment to SITS
    Given the following "activities" exist:
      | activity | name     | intro   | course | idnumber | section |
      | assign   | Assign 1 | A1 desc | C1     | assign1  | 1       |
    And the following "mod_assign > submissions" exist:
      | assign  | user     | onlinetext                          |
      | assign1 | student1 | This is a submission for assignment |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Assign 1  | student1 | 50    |
    And the "mod_assign" "assign1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as admin
    And I click on "Site administration" "link"
    And I click on "Plugins" "link"
    And I click on "Lifecycle" "link"
    And I should see "a screenshot will be taken here"
#    And I should see "course_year" in the "s_block_lifecycle_clcfield" "select"
