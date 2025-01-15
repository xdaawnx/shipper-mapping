<?php
namespace App\Helper;

class MappingLionCoverage {

    public const CITYEXCEPTION = [
        "BANJAR NEGARA" => "Banjarnegara",
        "BUKIT TINGGI" => "Bukittinggi",
        "BAU BAU" => "Bau-Bau",
        "DENPASAR KOTA" => "Denpasar",
        'FAK FAK' => "Fakfak",
        'GUNUNG SITOLI' => "Gunungsitoli",
        'JOGJAKARTA' => "Yogyakarta",
        'KOTA MEDAN' => "Medan",
        'MA BUNGO' => "Bungo",
        'PALANGKARAYA' => "Palangka Raya",
        'KABUPATEN SEMARANG' => "Semarang, Kab",
        'TOLI TOLI' => "Toli-Toli",
        'MAKASAR' => "Makassar",


     ];
    public const SKIPCITY = [
        "ATAMBUA" => 404,
        "AEK GODANG" => 404,
        "BANDUNG SELATAN" => 404,
        'BOGOR KABUPATEN' => 404,
        "BOGOR KAB" => 404,
        "BAJAWA" => 404,
        "ARGAMAKMUR" => 404,
        "CIKARANG" => 404,
        "DOBO" => 404,
        'SILANGIT' => 404,
        'TOBELO' => 404,
        'LABUAN BAJO' => 404,
        'LUWUK' => 404,
        'LARANTUKA' => 404,
        'MEULABOH' => 404,
        'MELONGUANE' => 404,
        'MAUMERE' => 404,
        'MUNTILAN' => 404,
        'NAMLEA' => 404,
        'PANGKALAN BUN' => 404,
        'PURWOKERTO' => 404, // shipper ga ada
        'RAHA' => 404,
        'RUTENG' => 404,
        'SIDIKALANG' => 404,
        'SAMPIT' => 404,
        'SAUMLAKI' => 404,
        'TIMIKA' => 404, // shipper ga ada
        'TANJUNG BALAI KARIMUN' => 404,
        'TANJUNG PANDAN' => 404,
        'TANJUNG SELOR' => 404,
        'WAIKABUBAK' => 404,
        'TAKENGON' => 404,
        'WATES' => 404,
        'WAINGAPU' => 404,
        'WAMENA' => 404,
        'BULI' => 404,
        

        // "Kab. Mamuju Tengah"        => "mamuju",
        // "Kab. Morowali Utara"       => "Morowali",
        // "Kab. Muna Barat"           => "Muna",
        // "Kab. Konawe Kepulauan"     => "Konawe",
        // "Kab. Kolaka Timur"         => "Kolaka", // suburb ada yang tidak ada di db (Ueesi, Aere)
        // "Kab. Mahakam Ulu"          => "mahakam",
        // "Kab. Penukal Abab Lematang Ilir" => "penukal",
    ];
}