# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Office overview page — browse, filter, search, and create Documents,
  Spreadsheets, Presentations, and Diagrams from a single page (#5)

### Changed

- Updated app icon to Material Symbols (#108)

### Fixed

- Search now covers all Office file types, not just a subset (#15)
- Exclude external storage from the "Mine" filter (#48)
- Sort recent files newest-first (#85)
- Order and limit the DAV search so "Recent" is actually recent (#105)

### Security

- Address npm audit findings in dependencies (#24, #26, #31, #41)
