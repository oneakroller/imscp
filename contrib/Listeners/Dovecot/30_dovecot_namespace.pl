# i-MSCP iMSCP::Listener::Dovecot::Namespace listener file
# Copyright (C) 2017-2018 Laurent Declercq <l.declercq@nuxwin.com>
# Copyright (C) 2015-2017 Rene Schuster <mail@reneschuster.de>
#
# This library is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301 USA

#
## Creates the INBOX. as a compatibility name, so old clients can continue using it while new clients will use the
## empty prefix namespace.
#

package iMSCP::Listener::Dovecot::Namespace;

our $VERSION = '1.0.2';

use strict;
use warnings;
use iMSCP::EventManager;
use iMSCP::File;
use iMSCP::Servers::Po;
use version;

#
## Please, don't edit anything below this line
#

version->parse( "$::imscpConfig{'PluginApi'}" ) >= version->parse( '1.6.0' ) or die(
    sprintf( "The 30_dovecot_namespace.pl listener file version %s requires i-MSCP >= 1.6.0", $VERSION )
);

iMSCP::EventManager->getInstance()->registerOne( 'afterDovecotConfigure', sub
{
    my $dovecotConfdir = iMSCP::Servers::Po->factory()->{'config'}->{'PO_CONF_DIR'};
    iMSCP::File->new( filename => "$dovecotConfdir/imscp.d/30_dovecot_namespace_listener.conf" )->set( <<'EOT' )->save();
namespace inbox {
    separator = /
    prefix =
}

namespace compat {
    separator = .
    prefix = INBOX.
    inbox = no
    hidden = yes
    list = no
    alias_for =
}
EOT
} ) if index( $::imscpConfig{'iMSCP::Servers::Po'}, '::Dovecot::' ) != -1;;

1;
__END__
