@local @local_sitsgradepush

Feature: Marks transfer from Moodle to SITS
  In order to transfer marks from Moodle to SITS
  As a teacher with the appropriate permissions
  I need to go to the SITS Marks Transfer dashboard page and transfer marks

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
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "1" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Transfer marks for a quiz to SITS
    Given the following "activities" exist:
      | activity | name   | intro   | course | idnumber | section |
      | quiz     | Quiz 1 | Q1 desc | C1     | quiz1    | 1       |
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
      | 2    | True     |
      | 3    | False    |
    And user "student2" has attempted "Quiz 1" with responses:
      | slot | response |
      | 2    | True     |
      | 3    | True     |
    And the "mod_quiz" "quiz1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "2" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Transfer marks for a grade item to SITS
    Given the following "grade items" exist:
      | itemname     | course | idnumber   |
      | Grade Item 1 | C1     | gradeitem1 |
    And the following "grade grades" exist:
      | gradeitem    | user     | grade |
      | Grade Item 1 | student1 | 21.00 |
      | Grade Item 1 | student2 | 50.00 |
    And the course "C1" is regraded
    And the "gradeitem" "gradeitem1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "2" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Transfer marks for a grade category to SITS
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
    And the "gradecategory" "Grade category 1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "2" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript @_file_upload
  Scenario: Transfer marks for coursework to SITS
    Given the "mod_coursework" plugin is installed
    And the following "activities" exist:
      | activity   | name         | intro    | course | idnumber    | section | allowearlyfinalisation | numberofmarkers |
      | coursework | Coursework 1 | CW1 desc | C1     | coursework1 | 1       | 1                      | 1               |
    And the following "permission overrides" exist:
      | capability             | permission | role           | contextlevel | reference |
      | mod/coursework:publish | Allow      | editingteacher | Course       | C1        |
    And I am on the "Course 1" course page logged in as student1
    And I follow "Coursework 1"
    And I click on "Upload your submission" "link"
    And I upload "lib/tests/fixtures/empty.txt" file to "Upload a file" filemanager
    And I press "Submit"
    And I press "Finalise your submission"
    And I press "Yes"
    And I log out
    And I am on the "Course 1" course page logged in as teacher1
    And I follow "Coursework 1"
    And I click on "Add feedback" "link"
    And I press "Save and finalise"
    And I reload the page
    And I click on "Release the marks" "link"
    And I press "Confirm"
    And the "mod_coursework" "coursework1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "1" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Transfer marks for lesson to SITS
    Given the following "activities" exist:
      | activity | name             | course | idnumber |
      | lesson   | Test lesson name | C1     | lesson1  |
    And the following "mod_lesson > pages" exist:
      | lesson           | qtype     | title                 | content                   |
      | Test lesson name | truefalse | True/false question 1 | Paper is made from trees. |
      | Test lesson name | truefalse | True/false question 2 | The sky is Pink.          |
    And the following "mod_lesson > answers" exist:
      | page                  | answer | response | jumpto    | score |
      | True/false question 1 | True   | Correct  | Next page | 1     |
      | True/false question 1 | False  | Wrong    | This page | 0     |
      | True/false question 2 | False  | Correct  | Next page | 1     |
      | True/false question 2 | True   | Wrong    | This page | 0     |
    When I am on the "Test lesson name" "lesson activity" page logged in as student1
    And I should see "Paper is made from trees."
    And I set the following fields to these values:
      | True | 1 |
    And I press "Submit"
    And I press "Continue"
    And I should see "The sky is Pink."
    And I set the following fields to these values:
      | True | 1 |
    And I press "Submit"
    And I press "Continue"
    And the "mod_lesson" "lesson1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page logged in as teacher1
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "1" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"

  @javascript
  Scenario: Transfer marks for lti to SITS
    Given the following "mod_lti > tool types" exist:
      | name            | baseurl                                   | coursevisible | state |
      | Teaching Tool 1 | /mod/lti/tests/fixtures/tool_provider.php | 2             | 1     |
    And I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    And I add a "Teaching Tool 1" to section "1" using the activity chooser
    And I set the field "Activity name" to "lti 1"
    And I press "Save and return to course"
    And I am on the "Course 1" "grades > Grader report > View" page
    And I give the grade "90.00" to the user "Student1 Test" for the grade item "lti 1"
    And I press "Save changes"
    And the "mod_lti" "lti 1" is mapped to "72hr take-home examination (3000 words)"
    And I am on the "Course 1" course page
    And I click on "More" "link" in the ".secondary-navigation" "css_element"
    And I select "SITS Marks Transfer" from secondary navigation
    And I should see "1" marks to transfer for "72hr take-home examination (3000 words)"
    And I click on the "Transfer marks" button for "72hr take-home examination (3000 words)"
    And I press "Confirm"
    And I run the scheduled task "\local_sitsgradepush\task\pushtask"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "0" marks to transfer for "72hr take-home examination (3000 words)"
