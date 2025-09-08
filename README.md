# SITS Marks Transfer #

This plugin adds a course level dashboard to manage the transfer of Marks from Moodle into SITS

It is designed for re-usability by other Moodle-SITS institutions and is intended as an architectural design proof of concept towards a full set of reusable Moodle-SITS integration plugins.

This does require some development to adopt but the barrier is greatly reduced.

## User Documentation
- [User Documentation wiki](https://ucldata.atlassian.net/wiki/spaces/MoodleResourceCentre/pages/31852705/SITS+Marks+Transfer)  
- [Initial Launch Blog post](https://blogs.ucl.ac.uk/digital-education/2024/03/18/initial-release-of-marks-transfer-available-on-ucl-moodle/)  
- [Gradebook functionality Blog post](https://blogs.ucl.ac.uk/digital-education/2024/06/06/update-on-the-moodle-sits-marks-transfer-wizard/)  
- [Re-Assessment functionality launch Blog post](https://blogs.ucl.ac.uk/digital-education/2024/09/19/new-moodle-assessment-features/#Mark:~:text=tracker%20documentation.-,Mark%20transfer%20update%C2%A0,-Finally%2C%20if%20you)  

## Getting Started
1) The plugin needs to know which SITS module deliveries relate to a specific course, we get this via our enrolment integration plugin [this interface](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/classes/manager.php#L252)  is logically seperated so it can be swapped out easily & provided by a different plugin, just needs to be wrapped into a site setting (merge requests welcome). 
2) SITS APIs - SITS has an API framework called [Stutalk](https://www.mysits.com/mysits/sits107/107manuals/index.htm?https://www.mysits.com/mysits/sits107/107manuals/mensys/02super/22stutalk/03st2/00toc.htm) which we called directly during the initial development phase, however at UCL we have an Enterprise API Management layer which allows for internal reusability & standardisation, we implemented these API clients as sub-plugins so that if you also need to use your own institutional bespoke API framework, you can just develop the API client without reinventing the rest of the wheel. The stutalk apiclient needs to implement the [getstudents](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/apiclients/easikit/classes/requests/getstudents.php) request.
3) Stutalk APIs. We can probably share some of the stutalk endpoints that were built, they just don't lend themselves to being in git.
4) As an additional safety, we prevent the configuration of marks transfer for previous academic years. This ends up calling our [Lifecycle](https://github.com/ucl-isd/moodle-block_lifecycle) plugin to find out the [end of the late assessment](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/classes/manager.php#L1070-L1072) period. This could easily be made optional.

## Features

- Link one Moodle activity to one SITS Assessment Component
- Link one Moodle activity to multiple SITS Assessment Component for seperate module deliveries
- only available for courses with mappings to a Module Delivery 
- push numerical grades 0-100
- manual trigger for push
- push the submission date & time
- Activities supported: Moodle Assignment, Quiz, LTI, Lesson, H5P (plugin), Coursework and Turnitin Assignment V2 (only with 1 part - most common).
- Select a Gradebook [grade item](https://docs.moodle.org/405/en/Grade_items) (covering LTIs, etc) and [grade category](https://docs.moodle.org/405/en/Grade_categories)
- Limit Assessment Component availability to ensure only compatible SITS Marking Schemes and SITS Assessment Types can be mapped. And for Exam Assessment types, only allow those components where the exam room code = EXAMMDLE
- Support for resits, retakes, repeats, re-assessments, Late Summer Assessments
- Late / non-submission reporting
- Automatically populate extra time / deadline extensions for [SORAs](https://www.ucl.ac.uk/students/support-and-wellbeing/disability-support/reasonable-adjustments-your-assessments) and for for [ECs](https://www.ucl.ac.uk/academic-manual/chapters/chapter-2-student-support-framework/2-short-term-illness-and-other-extenuating) for Moodle Quiz and Assignment

## Roadmap
We had our initial launch stage in March 2024, we have kept improving the tool since with many advanced features. , by implementing the following features in 2025:

- Advanced use cases of deadline extensions 
- Ability to transfer marks before they have been released to the Gradebook

### Turnitin V2 specific feature requests 
we have decided not to implement the following feature requests as they are specific to the Turnitin V2 plugin which has published End of Support and End of Life dates
- Select the marks from a specific part of a Turnitin Assignment with multiple parts
- Combining the source of the marks from multiple Turnitin assignments where these are used to handle seperate deadlines & multiple markers 
