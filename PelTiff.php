<?php

/*  PEL: PHP EXIF Library.  A library with support for reading and
 *  writing all EXIF headers in JPEG and TIFF images using PHP.
 *
 *  Copyright (C) 2004  Martin Geisler <gimpster@users.sourceforge.net>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program in the file COPYING; if not, write to the
 *  Free Software Foundation, Inc., 59 Temple Place, Suite 330,
 *  Boston, MA 02111-1307 USA
 */

/* $Id$ */


/**
 * Classes for dealing with TIFF data.
 *
 * @author Martin Geisler <gimpster@users.sourceforge.net>
 * @version $Revision$
 * @date $Date$
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public
 * License (GPL)
 * @package PEL
 */

/**#@+ Required class definitions. */
require_once('PelDataWindow.php');
require_once('PelIfd.php');
require_once('Pel.php');
/**#@-*/


/**
 * Class for handling TIFF data.
 *
 * @author Martin Geisler <gimpster@users.sourceforge.net>
 * @package PEL
 */
class PelTiff {

  /**
   * TIFF header.
   *
   * This must follow after the two bytes indicating the byte order.
   */
  const TIFF_HEADER = 0x002A;

  /**
   * The first Image File Directory, if any.
   *
   * @var PelIfd
   */
  private $ifd = null;


  /**
   * Construct a new object for holding TIFF data.
   *
   * The data given will be parsed and an internal tree representation
   * will be built.  If the data cannot be parsed correctly, a {@link
   * PelInvalidDataException} is thrown, explaining the problem.
   *
   * @param PelDataWindow the data from which the object will be
   * constructed.  This should be valid TIFF data, coming either
   * directly from a TIFF image or from the EXIF data in a JPEG image.
   */
  function __construct(PelDataWindow $d) {
    Pel::debug('Parsing %d bytes of TIFF data...', $d->getSize());

    /* There must be at least 8 bytes available: 2 bytes for the byte
     * order, 2 bytes for the TIFF header, and 4 bytes for the offset
     * to the first IFD. */
    if ($d->getSize() < 8)
      throw new PelInvalidDataException('Expected at least 8 bytes of TIFF ' .
                                        'data, found just %d bytes.',
                                        $d->getSize());

    /* Byte order */
    if ($d->strcmp(0, 'II')) {
      Pel::debug('Found Intel byte order');
      $d->setByteOrder(PelConvert::LITTLE_ENDIAN);
    } elseif ($d->strcmp(0, 'MM')) {
      Pel::debug('Found Motorola byte order');
      $d->setByteOrder(PelConvert::BIG_ENDIAN);
    } else {
      throw new PelInvalidDataException('Unknown byte order found in TIFF ' .
                                        'data: 0x%2X%2X',
                                        $d->getByte(0), $d->getByte(1));
    }
    
    /* Verify the TIFF header */
    if ($d->getShort(2) != self::TIFF_HEADER)
      throw new PelInvalidDataException('Missing TIFF magic value.');

    /* IFD 0 offset */
    $offset = $d->getLong(4);
    Pel::debug('First IFD at offset %d.', $offset);

    if ($offset > 0) {
      /* Parse the first IFD, this will automatically parse the
       * following IFDs and any sub IFDs. */
      $this->ifd = new PelIfd($d, $offset);
    }
  }


  /**
   * Return the first IFD.
   *
   * @return PelIfd the first IFD contained in the TIFF data, if any.
   * If there is no IFD null will be returned.
   */
  function getIfd() {
    return $this->ifd;
  }


  /**
   * Turn this object into bytes.
   *
   * TIFF images can have {@link PelConvert::LITTLE_ENDIAN
   * little-endian} or {@link PelConvert::BIG_ENDIAN big-endian} byte
   * order, and so this method takes an argument specifying that.
   *
   * @param PelByteOrder the desired byte order of the TIFF data.
   * This should be one of {@link PelConvert::LITTLE_ENDIAN} or {@link
   * PelConvert::BIG_ENDIAN}.
   *
   * @return string the bytes representing this object.
   */
  function getBytes($order) {
    if ($order == PelConvert::LITTLE_ENDIAN)
      $bytes = 'II';
    else
      $bytes = 'MM';
    
    /* TIFF magic number --- fixed value. */
    $bytes .= PelConvert::shortToBytes(self::TIFF_HEADER, $order);

    if ($this->ifd != null) {
      /* IFD 0 offset.  We will always start IDF 0 at an offset of 8
       * bytes (2 bytes for byte order, another 2 bytes for the TIFF
       * header, and 4 bytes for the IFD 0 offset make 8 bytes
       * together).
       */
      $bytes .= PelConvert::longToBytes(8, $order);
    
      /* The argument specifies the offset of this IFD.  The IFD will
       * use this to calculate offsets from the entries to their data,
       * all those offsets are absolute offsets counted from the
       * beginning of the data. */
      $bytes .= $this->ifd->getBytes(8, $order);
    } else {
      $bytes .= PelConvert::longToBytes(0, $order);
    }

    return $bytes;
  }


  /**
   * Return a string representation of this object.
   *
   * @return string a string describing this object.  This is mostly useful
   * for debugging.
   */
  function __toString() {
    $str = Pel::fmt("Dumping TIFF data...\n");
    if ($this->ifd != null)
      $str .= $this->ifd->__toString();

    return $str;
  }


  /**
   * Check if data is valid TIFF data.
   *
   * This will read just enough data from the data window to determine
   * if the data could be a valid TIFF data.  This means that the
   * check is more like a heuristic than a rigorous check.
   *
   * @param PelDataWindow the bytes that will be examined.
   *
   * @return boolean true if the data looks like valid TIFF data,
   * false otherwise.
   *
   * @see PelJpeg::isValid()
   */
  static function isValid(PelDataWindow $d) {
    /* First check that we have enough data. */
    if ($d->getSize() < 8)
      return false;

    /* Byte order */
    if ($d->strcmp(0, 'II')) {
      $d->setByteOrder(PelConvert::LITTLE_ENDIAN);
    } elseif ($d->strcmp(0, 'MM')) {
      Pel::debug('Found Motorola byte order');
      $d->setByteOrder(PelConvert::BIG_ENDIAN);
    } else {
      return false;
    }
    
    /* Verify the TIFF header */
    return $d->getShort(2) == self::TIFF_HEADER;
  }

}