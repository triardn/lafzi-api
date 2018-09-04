<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function index()
    {
        echo 'hai bro';
        // echo '<br/>';
        // var_dump(ekstrak_trigram('subhanallah'));
        // $file = Storage::disk('local')->get('fonetik_vokal.txt');
        // var_dump($file);
        // var_dump(file('../storage/app/quran_teks.txt', FILE_IGNORE_NEW_LINES));
    }

    public function search(Request $request)
    {
        if ($request->has('query')) {
            $query = $request->input('query');
        }

        $order = true;

        // perhitungan huruf vokal
        $vowel = 1;
        $filtered = 1;

        // profiling
        $time_start = microtime(true);

        $query_final = id_fonetik($query, !$vowel);
        $query_trigrams_count = strlen($query_final) - 2;

        if ($vowel) {
            $term_list_filename = '../storage/app/index_termlist_vokal.txt';
            $post_list_filename = '../storage/app/index_postlist_vokal.txt';
        } else {
            $term_list_filename = '../storage/app/index_termlist_nonvokal.txt';
            $post_list_filename = '../storage/app/index_postlist_nonvokal.txt';
        }

        // baca data teks quran untuk ditampilkan
        $quran_text = file('../storage/app/quran_teks.txt', FILE_IGNORE_NEW_LINES);
        $quran_trans = file('../storage/app/trans-indonesian.txt', FILE_IGNORE_NEW_LINES);

        // khusus ayat dengan fawatihussuwar
        $quran_text_muqathaat = file('../storage/app/quran_muqathaat.txt', FILE_IGNORE_NEW_LINES);
        $quran_text_muqathaat_map = [];

        foreach ($quran_text_muqathaat as $line) {
            list($no_surah, $nama_surah, $no_ayat, $teks) = explode('|', $line);
            $quran_text_muqathaat_map[$no_surah][$no_ayat] = $teks;
        }

        // sistem cache
        $cache_file = '../cache/' . $query_final;
        if ($order) {
            $cache_file .= '_o';
        }
        if ($filtered) {
            $cache_file .= '_f';
        }

        if (file_exists($cache_file)) {
            // read from cache
            $cf = fopen($cache_file, 'r');
            exec('touch ' . $cache_file);

            $result = unserialize(fgets($cf));
            fclose($cf);

            $from_cache = true;

            $status = 200;
            $response['message'] = 'success';
            $response['data_count'] = count($result);
            $response['data'] = $result;
        } else {
            // DO ACTUAL SEARCH

            // pertama dengan threshold 0.8
            $th = 0.95; //0.8;
            $matched_docs = search($query_final, $term_list_filename, $post_list_filename, $order, $filtered, $th);

            // jika ternyata tanpa hasil, turunkan threshold jadi 0.7
            if (count($matched_docs) == 0) {
                $th = 0.8; //0.7;
                $matched_docs = search($query_final, $term_list_filename, $post_list_filename, $order, $filtered, $th);
            }

            // jika ternyata tanpa hasil, turunkan threshold jadi 0.6
            if (count($matched_docs) == 0) {
                $th = 0.7; //0.6;
                $matched_docs = search($query_final, $term_list_filename, $post_list_filename, $order, $filtered, $th);
            }

            // jika masih tanpa hasil, ya sudah
            if (count($matched_docs) > 0) {
                // baca file posisi mapping
                if ($vowel) {
                    $mapping_data = file('../storage/app/mapping_posisi_vokal.txt', FILE_IGNORE_NEW_LINES);
                } else {
                    $mapping_data = file('../storage/app/mapping_posisi.txt', FILE_IGNORE_NEW_LINES);
                }

                foreach ($matched_docs as $doc) {
                    list(, , , $doc_text) = explode('|', $quran_text[$doc->id - 1]);
                    $doc_text = ar_string_to_array($doc_text);

                    // memetakan posisi kemunculan untuk highlighting
                    $posisi_real = [];
                    $posisi_hilight = [];
                    $map_posisi = explode(',', $mapping_data[$doc->id - 1]);
                    $seq = [];

                    // pad by 3
                    foreach ($doc->LIS as $pos) {
                        $seq[] = $pos;
                        $seq[] = $pos + 1;
                        $seq[] = $pos + 2;
                    }
                    $seq = array_unique($seq);
                    foreach ($seq as $pos) {
                        $posisi_real[] = $map_posisi[$pos - 1];
                    }

                    if ($vowel) {
                        $doc->highlight_positions = longest_highlight_lookforward($posisi_real, 6);
                    } else {
                        $doc->highlight_positions = longest_highlight_lookforward($posisi_real, 6);
                    }

                    // penambahan bobot jika penandaan berakhir pada karakter spasi
                    $end_pos = end($doc->highlight_positions);
                    $end_pos = $end_pos[1];

                    if ($doc_text[$end_pos + 1] == ' ' || !isset($doc_text[$end_pos + 1])) {
                        $doc->score += 0.001;
                    } elseif (!isset($doc_text[$end_pos + 2]) || $doc_text[$end_pos + 2] == ' ') {
                        $doc->score += 0.001;
                    } elseif (!isset($doc_text[$end_pos + 3]) || $doc_text[$end_pos + 3] == ' ') {
                        $doc->score += 0.001;
                    }
                }

                // diurutkan
                usort($matched_docs, 'matched_docs_cmp');

                $max_score = $query_trigrams_count;

                $result = [];
                foreach ($matched_docs as $doc) {
                    list($d_no_surat, $d_nama_surat, $d_no_ayat, $d_isi_teks) = explode('|', $quran_text[$doc->id - 1]);
                    list(, , $terjemah) = explode('|', $quran_trans[$doc->id - 1]);

                    $percent_relevance = min(floor($doc->score / $max_score * 100), 100);
                    if ($percent_relevance == 0) {
                        $percent_relevance = 1;
                    }

                    // if ayat mengandung muqathaat
                    if (isset($quran_text_muqathaat_map[$d_no_surat][$d_no_ayat])) {
                        $verse = $quran_text_muqathaat_map[$d_no_surat][$d_no_ayat];
                    } else {
                        $verse = $d_isi_teks;
                    }

                    $data = [
                        'query' => $query,
                        'surah_number' => $d_no_surat,
                        'surah_name' => $d_nama_surat,
                        'verse_number' => $d_no_ayat,
                        'verse' => $verse,
                        'translate' => $terjemah,
                        'link' => 'http://quran.ksu.edu.sa/index.php?l=id#aya=' . $d_no_surat . '_' . $d_no_ayat . '&m=hafs&qaree=husary&trans=id_indonesian',
                    ];

                    array_push($result, $data);
                }

                // // write to cache
                // $cf = fopen($cache_file, 'w');
                // fwrite($cf, serialize($result));
                // fclose($cf);

                // $from_cache = false;

                // // clean cache except 50 newest; linux only
                // $old_caches = [];
                // exec("ls -t ../cache/ | sed -e '1,50d'", $old_caches);

                // if (count($old_caches) > 0) {
                //     foreach ($old_caches as $old_cache) {
                //         unlink('../cache/' . $old_cache);
                //     }
                // }

                $status = 200;
                $response['message'] = 'success';
                $response['data_count'] = count($result);
                $response['data'] = $result;
            } else {
                $status = 404;
                $result['message'] = 'Verse not found';
            }
        }

        return response()->json($response, $status);
    }
}
