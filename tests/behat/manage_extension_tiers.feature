@local @local_sitsgradepush @javascript
Feature: Manage extension tier configuration
  In order to configure RAA extension tiers
  As an administrator
  I need to be able to import extension tier configurations via CSV

  @_file_upload
  Scenario: Import extension tiers with replace mode
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > SITS" in site administration
    And I follow "Manage Extension Tiers"
    And I upload "local/sitsgradepush/tests/fixtures/extension_tiers_valid.csv" file to "CSV File" filemanager
    And I set the field "Import Mode" to "Replace all existing tiers"
    When I press "Preview"
    Then I should see "Import Preview"
    And I should see "Rows to import:"
    And I should see "CN01"
    And I should see "EC03"
    And I should see "HD02"
    When I press "Confirm Import"
    Then I should see "tier configurations imported successfully"
    And I should see "CN01"
    And I should see "EC03"
    And I should see "HD02"

  @_file_upload
  Scenario: Import extension tiers with update mode
    Given the following extension tiers exist:
      | assessmenttype | tier | extensiontype | extensionvalue | extensionunit | breakvalue | enabled |
      | CN01           | 1    | days          | 5              |               |            | 1       |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > SITS" in site administration
    And I follow "Manage Extension Tiers"
    Then I should see "CN01"
    And I should see "5 days"
    And I should not see "7 days"
    When I upload "local/sitsgradepush/tests/fixtures/extension_tiers_update.csv" file to "CSV File" filemanager
    And I set the field "Import Mode" to "Update existing tiers"
    And I press "Preview"
    And I press "Confirm Import"
    Then I should see "tier configurations imported successfully"
    And I should see "CN01"
    And I should see "EC03"
    And I should see "7 days"
    And I should not see "5 days"
