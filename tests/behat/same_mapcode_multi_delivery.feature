@local @local_sitsgradepush

Feature: Same-mapcode multi-delivery grade push
  In order to support modules with multiple deliveries sharing the same mapcode
  As a teacher with the appropriate permissions
  I need to be able to map the same activity to different deliveries and see correct marks counts

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
  Scenario: Same activity can be mapped to two deliveries sharing the same map code but different period slot code
    Given the following "activities" exist:
      | activity | name     | intro   | course | idnumber | section |
      | assign   | Assign 1 | A1 desc | C1     | assign1  | 1       |
    And the "mod_assign" "assign1" is mapped to "Same mapcode assessment T2/3"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I click on the "Select source" button for "Same mapcode assessment T2/3/S"
    And I click on the "Select" button for "Assign 1"
    And I press "Confirm"
    Then I should see "Assign 1" is mapped to "Same mapcode assessment T2/3"
    And I should see "Assign 1" is mapped to "Same mapcode assessment T2/3/S"

  @javascript
  Scenario: Marks count is correctly filtered by period slot code when two deliveries share the same map code
    Given the following "activities" exist:
      | activity | name     | intro   | course | idnumber | section |
      | assign   | Assign 1 | A1 desc | C1     | assign1  | 1       |
      | assign   | Assign 2 | A2 desc | C1     | assign2  | 1       |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Assign 1  | student1 | 70    |
      | Assign 1  | student3 | 60    |
      | Assign 2  | student2 | 80    |
    And the "mod_assign" "assign1" is mapped to "Same mapcode assessment T2/3"
    And the "mod_assign" "assign2" is mapped to "Same mapcode assessment T2/3/S"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    Then I should see "2" marks to transfer for "Same mapcode assessment T2/3"
    And I should see "1" marks to transfer for "Same mapcode assessment T2/3/S"
