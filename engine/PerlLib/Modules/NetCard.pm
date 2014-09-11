#!/usr/bin/perl

=head1 NAME

 Modules::NetCard - i-MSCP NetCard module

=cut

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2014 by internet Multi Server Control Panel
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
#
# @category     i-MSCP
# @copyright    2010-2014 by i-MSCP | http://i-mscp.net
# @author       Daniel Andreca <sci2tech@gmail.com>
# @link         http://i-mscp.net i-MSCP Home Site
# @license      http://www.gnu.org/licenses/gpl-2.0.html GPL v2

package Modules::NetCard;

use strict;
use warnings;

use iMSCP::Debug;
use iMSCP::Execute;
use parent 'Common::Object';

=head1 DESCRIPTION

 i-MSCP NetCard module.

=head1 PUBLIC METHODS

=over 4

=item process()

 Process module

 Return int 0 on success, other on failure

=cut

sub process
{
	my $self = $_[0];

	my ($stdour, $stderr);
	my $rs = execute(
		"$main::imscpConfig{'CMD_PERL'} $main::imscpConfig{'TOOLS_ROOT_DIR'}/imscp-net-interfaces-mngr stop",
		\$stdour,
		\$stderr
	);
	debug($stdour) if $stdour;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	$rs = execute(
		"$main::imscpConfig{'CMD_PERL'} $main::imscpConfig{'TOOLS_ROOT_DIR'}/imscp-net-interfaces-mngr start",
		\$stdour,
		\$stderr
	);
	debug($stdour) if $stdour;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	0;
}

=back

=head1 AUTHORS

 Daniel Andreca <sci2tech@gmail.com>
 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
