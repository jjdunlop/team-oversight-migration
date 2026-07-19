```text
                                          .--""--.
                            *    .       /  .--.  \
                     o/        *        ;  /    \  ;
                    /|      .    *      :  \    /  :
                    / \          .       \  '--'  /
                   /   \      *           '--..--'
                                              \\
     __________________________________________\\________________
    |    |    |    |    |    |    |    |    |   \\   |    |    |
    |____|____|____|____|____|____|____|____|____\\__|____|____|
    |    |    |    |    |    |    |    |    |     \\ |    |    |
    |____|____|____|____|____|____|____|____|______vv|____|____|
   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

        B u m p .   S e t .   S p i k e .   A d m i n i s t r a t e .
```

# Team Oversight Plugin

A comprehensive volleyball club management system for WordPress with Ultimate Member integration. The admin is split into two areas:

- **Club Membership** — club-wide member list and time-bound membership tiers (Full/Associate), granted by purchases or manually
- **VVL Oversight** — competition machinery: VVL teams, trial applications, team assignments, fee invoices, imports/exports

## Features

- **Trial Application System**: Users can apply for team trials through a front-end form
- **Team Assignment Management**: Admin can assign players to teams with multiple roles
- **Fee Calculation**: Automatic fee calculation based on MUS category and team role
- **Multi-Role Support**: Players can have multiple roles across different teams
- **Payment Tracking**: Track invoice amounts and outstanding balances per member
- **Membership Tiers**: Time-bound Full/Associate memberships granted automatically by WooCommerce purchases (per-product or per-category tier + term), with manual grants, revocation, and automatic role sync/expiry
- **Members Page**: One row per person — membership status and expiry, profile data, teams, fees owing, accreditation — filterable, sortable, CSV-exportable
- **Accreditation Management**: Import and track RevSport accreditation data
- **Oversight Dashboard**: Comprehensive view of MUS data, payments, accreditations, and team lists

## Installation

1. Ensure Ultimate Member plugin is installed and activated
2. Upload the `team-oversight` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin
4. Run the setup script to initialize data: access `/wp-content/plugins/team-oversight/setup-data.php` in browser
5. Go to Team Oversight menu in WordPress admin

## Initial Setup

### 1. Import Price Matrix
- Go to Team Oversight > Fee Matrix
- Click "Import Price Matrix from CSV" to load the fee structure

### 2. Create Trial Form Page
- Create a new WordPress page
- Add the shortcode `[team_trial_form]` to display the trial application form
- Publish the page for users to access

### 3. Add Team Assignments
- Go to Team Oversight > Team Assignments
- Add team assignments for existing users
- System will automatically generate invoices based on fee matrix

## Usage

### For Users (Front-end)
1. **Create Account**: Users must register through Ultimate Member
2. **Complete Profile**: Add education history for MUS category calculation
3. **Submit Trial Application**: Use the trial form to apply for teams
4. **Wait for Assignment**: Admin will review and assign to teams

### For Administrators

#### Dashboard Overview
- Navigate to Team Oversight > Dashboard
- View seasonal overview of:
  - MUS sport data (education-based categories)
  - Accreditation status
  - Payment status
  - Team lists and roles

#### Trial Management
- Team Oversight > Trial Applications
- Review pending applications
- Accept applications by assigning to teams
- Reject applications as needed

#### Team Assignments
- Team Oversight > Team Assignments
- Add new assignments manually
- View all current assignments
- Deactivate assignments when players leave

#### Fee Matrix Management
- Team Oversight > Fee Matrix
- View current fee structure
- Import updated price matrix from CSV

#### Data Import/Export
- **RevSport Import**: Upload CSV with accreditation data
- **Team Lists Export**: Export team rosters
- **MUS Membership Report**: Export membership report with MUS categories

## Data Structure

### Teams Available
- **Premier League**: PLD1-M, PLD1-W, PLD2-M, PLD2-W
- **State League**: SLD1B-M, SLD1W-M, SLD2-M, SLD3-M, SLD1B-W, SLD1W-W, SLD2-W, SLD3-W
- **Youth State League**: YSL17D1B-B, YSL17D1R-B, YSL17D1W-B, YSL17D1A-G, YSL17D1B-G
- **Junior Premier League**: JPLD1-B, JPLD1-G

### Player Positions
- Setter, Middle, Outside, Opposite, Libero, Universal

### Player Roles
- Coach, Assistant Coach, Team Manager, Playing Member, Training Only Member, Supporter

### Fee Classes
- Junior U/19 (VVL) - Juniors in senior leagues
- Melb Uni Student - Current Melbourne Uni students
- Other Student - Current students from other universities
- JPL/YSL - Juniors in junior leagues
- Full Adult - Everyone else

## Fee Calculation Logic

1. **Determine MUS Category**: Based on education history in Ultimate Member profile
2. **Find All Roles**: Check all team assignments for the player
3. **Calculate Minimum Fee**: Apply fee matrix to find cheapest role
4. **Generate Invoice**: Create single invoice with minimum fee amount
5. **League-Specific Fees**: Junior leagues may have different rates

## File Formats

### RevSport CSV Import
Required columns: VA ID, First name, Last name, Date of birth, Gender identity, Mobile phone, Email address, Payment status, Payment date, VA Coach, VA Referee

## Membership Tiers

Memberships are time-bound grants stored in `wp_team_memberships`; a member's current status is their highest unexpired grant. The `full-member` / `associate-member` WordPress roles are kept in sync daily (and on every grant) so role-based gating and the profile shortcode keep working — including automatic demotion when the last grant expires.

- **From purchases**: set "Membership tier granted" + "Membership term (months)" on a product (Product > Edit > General), or configure a product-category rule on the Members page. Terms run from the purchase date. Only explicitly configured products/categories grant anything.
- **Manual grants**: Members page > "Grant a membership manually" (tier + valid-until date + note, recorded against the granting admin).
- **Seeding**: Members page > "Seed memberships from this year's purchases" converts the current year's qualifying purchases into dated grants (Full = 12 months, Associate = 3 months from purchase). Dry-run available; idempotent.

## Database Tables

- `wp_team_accreditations` - RevSport accreditation data
- `wp_team_invoices` - Player invoices and payment tracking
- `wp_team_assignments` - Team/role assignments with history
- `wp_team_memberships` - Time-bound membership tier grants
- `wp_fee_matrix` - Fee calculation matrix
- `wp_trial_applications` - Trial applications and status

## Troubleshooting

### Common Issues

1. **Plugin won't activate**: Ensure Ultimate Member is installed first
2. **Fee matrix empty**: Run the price matrix import
3. **Users can't submit trials**: Check they are logged in with Ultimate Member account
4. **Invoices not generating**: Verify fee matrix has data for the player's MUS category
5. **Import failures**: Check CSV format matches expected columns

### Support

For technical issues:
1. Check WordPress error logs
2. Verify Ultimate Member is properly configured
3. Ensure all required user meta fields are populated
4. Check database table creation was successful

## Development Notes

### Adding New Teams
Update the `get_teams()` method in `class-database.php`

### Modifying Fee Structure
Update the price matrix CSV and re-import, or modify database directly

### Custom Fields
Additional Ultimate Member fields can be added for enhanced functionality

### Extending Functionality
The plugin is built modularly - new features can be added by creating additional classes in the `/includes/` directory.

## Security Notes

- All form inputs are sanitized and validated
- WordPress nonces protect against CSRF attacks
- Database queries use prepared statements
- File uploads are restricted to CSV format
- User permissions are checked for admin functions