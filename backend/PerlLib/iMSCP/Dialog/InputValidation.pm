=head1 NAME

iMSCP::Dialog::InputValidation

=cut

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2018 Laurent Declercq <l.declercq@nuxwin.com>
#
# This library is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA

package iMSCP::Dialog::InputValidation;

use strict;
use warnings;
use Carp qw/ croak /;
use Data::Validate::Domain qw/ is_domain is_hostname /;
use DateTime::TimeZone;
use Email::Valid;
use iMSCP::Boolean;
use iMSCP::Database;
use iMSCP::Net;
use List::Compare::Functional qw/ get_intersection /;
use Net::LibIDN qw/ idn_to_ascii /;
use parent 'Exporter';

our @EXPORT = qw/
    isValidUsername isValidPassword isValidEmail isValidHostname isValidDomain isValidIpAddr isRoutableIpAddr
    isValidTimezone isValidDbName isNumber isNumberInRange isStringInList isStringNotInList isOneOfStringsInList isValidNumberRange
    isNotEmpty isAvailableSqlUser /;

our $lastValidationError = '';

=head1 DESCRIPTION

 Provides set of routines for ease of user inputs validation.

=head1 PUBLIC METHODS

=over 4

=item isValidUsername( $username )

 Is the given username valid?

 Param string $username Username
 Return bool TRUE if the given username is valid, FALSE otherwise, croak on failure

=cut

sub isValidUsername( $ )
{
    my ( $username ) = @_;

    defined $username or croak( 'Missing $username parameter' );
    my $length = length $username;

    $lastValidationError = '';
    return TRUE if $length >= 3 && $length <= 16 && $username =~ /^[\x30-\x39\x41-\x5a\x61-\x7a\x5f]+$/;

    $lastValidationError = <<"EOF";
\\Z1Invalid or unauthorized username.\\Zn

 - Username must be between 3 and 16 characters long.
 - Only ASCII alphabet, number and underscore characters are allowed.
EOF
    FALSE;
}

=item isValidPassword( $password )

 Is the given password valid?
 
 Param string $password Password
 Return bool TRUE if the given password is valid, FALSE otherwise, croak on failure

=cut

sub isValidPassword( $ )
{
    my ( $password ) = @_;

    defined $password or croak( 'Missing $password parameter' );
    my $length = length $password;

    $lastValidationError = '';
    return TRUE if $length >= 6 && $length <= 32 && $password =~ /^[\x30-\x39\x41-\x5a\x61-\x7a]+$/;

    $lastValidationError = <<"EOF";
\\Z1Invalid password.\\Zn

 - Password must be between 6 and 32 characters long.
 - Only ASCII alphabet and number characters are allowed.
EOF
    FALSE;
}

=item isValidEmail( $email )

 Is the given email valid?

 Param string $email Email
 Return bool TRUE if the given email is valid, FALSE otherwise, croak on failure

=cut

sub isValidEmail( $ )
{
    my ( $email ) = @_;

    defined $email or croak( 'Missing $email parameter' );

    $lastValidationError = '';
    return TRUE if Email::Valid->address( $email );

    $lastValidationError = <<"EOF";
\\Z1Invalid email address.\\Zn
EOF
    FALSE;
}

=item isValidHostname( $hostname )

 Is the given hostname valid?
 
 Param string $hostname Hostname
 Return bool TRUE if the given hostname is valid, FALSE otherwise, croak on failure

=cut

sub isValidHostname( $ )
{
    my ( $hostname ) = @_;

    defined $hostname or croak( 'Missing $hostname parameter' );

    $lastValidationError = '';
    return TRUE if $hostname !~ /\.$/ && ( $hostname =~ tr/.// ) >= 2 && is_hostname( idn_to_ascii( $hostname, 'utf-8' ));

    $lastValidationError = <<"EOF";
\\Z1Invalid hostname.\\Zn

 - Hostname must comply to RFC 1123 and 5890
 - The hostname must be a fully qualified hostname (FQHN).
EOF
    FALSE;
}

=item isValidDomain( $domainName )

 Is the given domain name valid?

 Param string $domain Domain name
 Return bool TRUE if the given domain name is valid, FALSE otherwise, croak on failure

=cut

sub isValidDomain( $ )
{
    my ( $domainName ) = @_;

    defined $domainName or croak( 'Missing $domainName parameter' );

    $lastValidationError = '';
    return TRUE if $domainName !~ /\.$/ && is_domain( idn_to_ascii( $domainName, 'utf-8' ), { domain_disable_tld_validation => TRUE } );

    $lastValidationError = <<"EOF";
\\Z1Invalid domain name.\\Zn

 - Domain name must comply to RFC 1123 and 5890
EOF
    FALSE;
}

=item isValidIpAddr( $ipAddr [, $typeReg = ANY ] )

 Is the given IP address valid?

 Param string $ipAddr IP address
 Param regexp|undef typeReg Regexp defining allowed IP type
 Return bool TRUE if the given IP address is valid, FALSE otherwise, croak on failure

=cut

sub isValidIpAddr( $;$ )
{
    my ( $ipAddr, $typeReg ) = @_;

    defined $ipAddr or croak( 'Missing $ipAddr parameter' );

    $lastValidationError = '';
    my $net = iMSCP::Net->getInstance();
    return TRUE if $net->isValidAddr( $ipAddr ) && ( !defined $typeReg || $net->getAddrType( $ipAddr ) =~ /^$typeReg$/ );

    $lastValidationError = <<"EOF";
\\Z1Invalid or unauthorized IP address.\\Zn
EOF
    FALSE;
}

=item isRoutableIpAddr( $ipAddr )

 Is the given IP address valid and routable?

 Param string $ipAddr IP address
 Return bool TRUE if the given IP address is valid and routable, FALSE otherwise, croak on failure

=cut

sub isRoutableIpAddr( $ )
{
    my ( $ipAddr ) = @_;

    defined $ipAddr or croak( 'Missing $ipAddr parameter' );

    $lastValidationError = '';
    return iMSCP::Net->getInstance()->isRoutableAddr( $ipAddr );

    $lastValidationError = <<"EOF";
\\Z1Invalid or unauthorized IP address: The IP address is not valid or not routable.\\Zn
EOF
    FALSE;
}

=item isValidDbName( $dbName )

 Is the given database name valid?

 Param string $email Email
 Return bool TRUE if the given email is valid, FALSE otherwise, croak on failure

=cut

sub isValidDbName( $ )
{
    my ( $dbName ) = @_;

    defined $dbName or croak( 'Missing $dbName parameter' );
    my $length = length $dbName;

    $lastValidationError = '';
    return TRUE if $length >= 3 && $length <= 16 && $dbName =~ /^[\x30-\x39\x41-\x5a\x61-\x7a\x5f]+$/;

    $lastValidationError = <<"EOF";
\\Z1Invalid or unauthorized database name.\\Zn

 - Database name must be between 3 and 16 characters long.
 - Only ASCII alphabet, number and underscore characters are allowed.
EOF
    FALSE;
}

=item isValidTimezone( $timezone )

 Is the given timzone name valid?

 Param string timezone Timezone
 Return bool TRUE if the given timezone is valid, FALSE otherwise, croak on failure

=cut

sub isValidTimezone( $ )
{
    my ( $timezone ) = @_;

    defined $timezone or croak( 'Missing $timezone parameter' );

    $lastValidationError = '';
    return TRUE if DateTime::TimeZone->is_valid_name( $timezone );

    $lastValidationError = <<"EOF";
\\Z1Invalid timezone.\\Zn

 - Consult http://php.net/manual/en/timezones.php for a list of valid timezones.
EOF
    FALSE;
}

=item isNumber( $number )

 Is the given number valid?

 Param int $number Number
 Return bool TRUE if the given number is valid, FALSE otherwise, croak on failure

=cut

sub isNumber( $ )
{
    my ( $number ) = @_;

    defined $number or croak( 'Missing $timezone parameter' );

    $lastValidationError = '';
    return TRUE if $number =~ /^[\x30-\x39]+$/;

    $lastValidationError = <<"EOF";
\\Z1Invalid number.\\Zn
EOF
    FALSE;
}

=item isValidNumberRange( $numberRange, \$n1, \$n2 )

 Is the given number range a valid number range?

 Param string $numberRange Number range
 Param scalarref \$n1 First number in range
 Param scalarref \$n2 Last number in range
 Return bool TRUE if the given number range is valid, FALSE otherwise, croak on failure

=cut

sub isValidNumberRange( $$$ )
{
    my ( $numberRange, $n1, $n2 ) = @_;

    defined $numberRange or croak( 'Missing $numberRange parameter' );
    defined $n1 or croak( 'Missing $n1 parameter' );
    defined $n2 or croak( 'Missing $n2 parameter' );

    $lastValidationError = '';
    return TRUE if ( ${ $n1 }, ${ $n2 } ) = $numberRange =~ /^([\x30-\x39]+)\s+([\x30-\x39]+)$/;

    $lastValidationError = <<"EOF";
\\Z1Invalid number range.\\Zn

- Number range must be two numbers separated by a space.
EOF
    FALSE;
}

=item isNumberInRange( $number, $start, $end )

 Is the given number in the given range?

 Param int $number Number
 Param int $start Start of range
 Param int $end End of range
 Return bool TRUE if the given number is under the given range, FALSE otherwise, croak on failure

=cut

sub isNumberInRange( $$$ )
{
    my ( $number, $start, $end ) = @_;

    defined $number or croak( 'Missing $number parameter' );
    defined $start or croak( 'Missing $start parameter' );
    defined $end or croak( 'Missing $end parameter' );

    $lastValidationError = '';
    return TRUE if $number =~ /^[\x30-\x39]+$/ && $number >= $start && $number <= $end;

    $lastValidationError = <<"EOF";
\\Z1Invalid number.\\Zn

 - Number $number must be in range from $start to $end.
EOF
    FALSE;
}

=item isStringInList( $string, @stringList )

 Is the given string in the given list?

 Note: Comparison is case-sensitive.

 Param string string String
 Param list @stringList String list
 Return bool TRUE if the given string is the given list, FALSE otherwise, croak on failure

=cut

sub isStringInList( $@ )
{
    my ( $string, @stringList ) = @_;

    defined $string or croak( 'Missing $string parameter' );

    $lastValidationError = '';
    return TRUE if grep { $string eq $_ } @stringList;

    my $entries = join ', ', @stringList;
    $lastValidationError = <<"EOF";
\\Z1Invalid entry.\\Zn

 - Following entries are allowed: $entries
EOF
    FALSE;
}

=item isStringNotInList( $string, @stringList )

 Is the given string not in the given list?

 Note: Comparison is case-sensitive.

 Param string string String
 Param list @stringList String list
 Return bool TRUE if the given string is the given list, FALSE otherwise, croak on failure

=cut

sub isStringNotInList( $@ )
{
    my ( $string, @stringList ) = @_;

    defined $string or croak( 'Missing $string parameter' );

    $lastValidationError = '';
    return TRUE unless grep { $string eq $_ } @stringList;

    my $entries = join ', ', @stringList;
    $lastValidationError = <<"EOF";
\\Z1Invalid entry.\\Zn

 - Following entries are not allowed: $entries
EOF
    FALSE;
}

=item isOneOfStringsInList( \@stringsListL, \@stringListR )

 Is at least one string of the first list of strings in the the second list of strings?

 Note: Comparison is case-sensitive.

 Param array \@stringsListL List of strings to search in the second list of strings
 Param array \@stringListR  List of string in which to search strings from the first list of strings
 Return bool TRUE if at least one string of the first list of strings is found in the second list of string, FALSE otherwise

=cut

sub isOneOfStringsInList
{
    my ( $stringsListL, $stringListR ) = @_;

    scalar get_intersection( '-u', [ $stringsListL, $stringListR ] );
}

=item isNotEmpty( $string )

 Is the given string not an empty string?

 Param string $string String
 Return bool TRUE if the given string is not empty, FALSE otherwise, croak on failure

=cut

sub isNotEmpty( $ )
{
    my ( $string ) = @_;

    defined $string or croak( 'Missing $string parameter' );

    $lastValidationError = '';
    return TRUE if length $string && $string =~ /[^\s]/;

    $lastValidationError = <<"EOF";
\\Z1Entry cannot be empty.\\Zn
EOF
    FALSE;
}

=item isAvailableSqlUser( $username )

 Is the given SQL user available?

 This routine make sure that the given SQL user is not already used by a customer.

 Param string $username SQL username
 Return bool TRUE if the given SQL user is available, FALSE otherwise, croak on failure

=cut

sub isAvailableSqlUser( $ )
{
    my ( $username ) = @_;

    defined $username or croak( 'Missing $username parameter' );

    $lastValidationError = '';

    my $db = iMSCP::Database->getInstance();

    local $@;
    my $oldDbName = eval { $db->useDatabase( ::setupGetQuestion( 'DATABASE_NAME' )); };
    if ( $@ ) {
        return TRUE if $@ =~ /unknown database/i; # On fresh installation, there is no database yet
        die;
    }

    my $row = $db->selectrow_hashref( 'SELECT 1 FROM sql_user WHERE sqlu_name = ? LIMIT 1', undef, $username );

    $db->useDatabase( $oldDbName ) if length $oldDbName;

    return TRUE unless $row;

    $lastValidationError = <<"EOF";
\\Z1Invalid SQL username.\\Zn

 - The given SQL user is already used by one of your customers.
EOF
    FALSE;
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
