<?php

class SemanticChunking {

    private $embedding_model;
    private $window_size;
    private $transfer_sentence_count;

    public function __construct($embedding_model, $window_size, $transfer_sentence_count) {
        $this->embedding_model = $embedding_model;
        $this->window_size = $window_size;
        $this->transfer_sentence_count = $transfer_sentence_count;
    }

    /**
     * Bir segmenti, maksimum karakter uzunluğunu aşmayacak şekilde kelime sınırlarına göre parçalara böler.
     */
    public function splitSegmentByWordBoundary($segment, $max_length) {
        $words = preg_split('/\s+/', $segment);
        $parts = [];
        $current_part = "";
        foreach ($words as $word) {
            if (mb_strlen($current_part) + mb_strlen($word) + 1 > $max_length) {
                $parts[] = trim($current_part);
                $current_part = $word;
            } else {
                $current_part = $current_part ? $current_part . " " . $word : $word;
            }
        }
        if (trim($current_part) !== "") {
            $parts[] = trim($current_part);
        }
        return $parts;
    }

    /**
     * Segmentleri ön işler; boşlukları temizler, kısa segmentleri atar ve 10000 karakteri aşan segmentleri kelime bazında böler.
     */
    public function preprocessSegments($segments) {
        $processed = [];
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if (mb_strlen($seg) < 3) {
                continue;
            }
            if (mb_strlen($seg) > 10000) {
                $processed = array_merge($processed, $this->splitSegmentByWordBoundary($seg, 10000));
            } else {
                $processed[] = $seg;
            }
        }
        return $processed;
    }

    /**
     * Metni, noktalama işaretleri ve yeni satır karakterlerini baz alarak cümlelere böler.
     * Daha sonra cümleler, yaklaşık 750 karakterlik parçalarda birleştirilir.
     */
    public function splitTextIntoSentences($text) {
        $pattern = '/(?<=[a-zA-Z0-9])([.!])(?=\s*[A-Z])|(?<=\n)/';
        $temp_parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $temp_parts = array_map(function($part) {
            return $part ?? "";
        }, $temp_parts);

        $reattached_sentences = [];
        $i = 0;
        $count = count($temp_parts);
        while ($i < $count) {
            $chunk = $temp_parts[$i];
            if ($i + 1 < $count && preg_match('/^[.!]$/', $temp_parts[$i+1])) {
                $chunk .= $temp_parts[$i+1];
                $i++;
            }
            $chunk = trim($chunk);
            if ($chunk !== "") {
                $reattached_sentences[] = $chunk;
            }
            $i++;
        }

        $merged_sentences = [];
        $buffer = "";
        foreach ($reattached_sentences as $sentence) {
            if (mb_strlen($buffer) + mb_strlen($sentence) < 750) {
                $buffer = $buffer ? $buffer . " " . $sentence : $sentence;
            } else {
                if ($buffer !== "") {
                    $merged_sentences[] = $buffer;
                }
                $buffer = $sentence;
            }
        }
        if ($buffer !== "") {
            $merged_sentences[] = $buffer;
        }
        return $merged_sentences;
    }

    /**
     * Metni, cümlelere ayırıp ön işlemden geçirerek kural tabanlı segmentlere böler.
     */
    public function ruleBasedSegmentation($text) {
        $segments = $this->splitTextIntoSentences($text);
        $segments = $this->preprocessSegments($segments);
        return $segments;
    }

    /**
     * Verilen metinler için OpenAI API'sini kullanarak embedding'leri oluşturur.
     * API çağrısını projenize uygun şekilde uyarlamanız gerekir.
     */
    public function createEmbeddings($texts) {
        // Örneğin, OpenAI API çağrısını aşağıdaki gibi yapabilirsiniz.
        $response = openaiEmbeddingCreate([
            'input' => $texts,
            'model' => $this->embedding_model
        ]);
        $embeddings = [];
        foreach ($response['data'] as $d) {
            $embeddings[] = $d['embedding'];
        }
        return $embeddings;
    }

    /**
     * İki embedding arasındaki cosine similarity değerini hesaplar.
     */
    private function cosineSimilarity($vec1, $vec2) {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($vec1 as $i => $valueA) {
            $valueB = $vec2[$i];
            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }
        if ($normA == 0 || $normB == 0) {
            return 0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Verilen divergence değerlerine göre her pencere için dinamik bir threshold hesaplar.
     * Ortalama ve standart sapmaya göre, farklı faktörler uygulanır.
     */
    public function calculateDynamicThresholdFromDivergences($divergences) {
        $mean_div = array_sum($divergences) / count($divergences);
        $variance = 0.0;
        foreach ($divergences as $d) {
            $variance += pow($d - $mean_div, 2);
        }
        $variance /= count($divergences);
        $std_div = sqrt($variance);
        if ($std_div < 0.1) {
            $factor = 1.4;
        } elseif ($std_div > 0.3) {
            $factor = 1.0;
        } else {
            $factor = 1.2;
        }
        return $mean_div + $std_div * $factor;
    }

    /**
     * Segmentleri, pencere içerisindeki embedding'ler arası divergence hesaplayarak semantik olarak birleştirir.
     * Her pencere için dinamik threshold değeri belirlenir.
     */
    public function semanticMerging($segments) {
        $n = count($segments);
        if ($n < $this->window_size) {
            return [implode(" ", $segments)];
        }
        
        $embeddings = $this->createEmbeddings($segments);
        $split_points = [];
        
        for ($window_start = 0; $window_start <= $n - $this->window_size; $window_start++) {
            $window_embeddings = array_slice($embeddings, $window_start, $this->window_size);
            $window_divergences = [];
            for ($i = 0; $i < $this->window_size - 1; $i++) {
                $sim = $this->cosineSimilarity($window_embeddings[$i], $window_embeddings[$i+1]);
                $divergence = 1 - $sim;
                $window_divergences[] = $divergence;
            }
            $local_threshold = $this->calculateDynamicThresholdFromDivergences($window_divergences);
            for ($i = 0; $i < count($window_divergences); $i++) {
                if ($window_divergences[$i] > $local_threshold) {
                    $global_index = $window_start + $i + 1;
                    $split_points[$global_index] = true;
                }
            }
        }
        
        $split_points = array_keys($split_points);
        sort($split_points);
        $chunks = [];
        $last_split = 0;
        foreach ($split_points as $point) {
            $chunk = implode(" ", array_slice($segments, $last_split, $point - $last_split));
            if (trim($chunk) !== "") {
                $chunks[] = $chunk;
            }
            $last_split = $point;
        }
        if ($last_split < $n) {
            $chunk = implode(" ", array_slice($segments, $last_split));
            if (trim($chunk) !== "") {
                $chunks[] = $chunk;
            }
        }
        return $chunks;
    }

    /**
     * Chunk'lar arasındaki sınırları ayarlamak için kullanılır.
     * Bir sonraki chunk'ın ilk 'transfer_sentence_count' cümlesi,
     * önceki chunk ile karşılaştırılarak transfer edilir.
     */
    public function adjustBoundaries($chunks) {
        $adjusted_chunks = $chunks;
        $candidate_texts = [];
        $previous_texts = [];
        $remainder_texts = [];
        $indices = [];
        
        for ($i = 0; $i < count($adjusted_chunks) - 1; $i++) {
            $next_sentences = $this->splitTextIntoSentences($adjusted_chunks[$i+1]);
            if (!$next_sentences || count($next_sentences) <= $this->transfer_sentence_count) {
                continue;
            }
            $candidate_text = implode(" ", array_slice($next_sentences, 0, $this->transfer_sentence_count));
            $remainder = implode(" ", array_slice($next_sentences, $this->transfer_sentence_count));
            $candidate_texts[] = $candidate_text;
            $previous_texts[] = $adjusted_chunks[$i];
            $remainder_texts[] = $remainder;
            $indices[] = $i;
        }
        
        if (!empty($candidate_texts)) {
            $candidate_embeddings = $this->createEmbeddings($candidate_texts);
            $previous_embeddings = $this->createEmbeddings($previous_texts);
            $remainder_embeddings = $this->createEmbeddings($remainder_texts);
            
            foreach ($indices as $key => $i) {
                $candidate_emb = $candidate_embeddings[$key];
                $prev_emb = $previous_embeddings[$key];
                $next_emb = $remainder_embeddings[$key];
                $sim_prev = $this->cosineSimilarity($prev_emb, $candidate_emb);
                $sim_next = $this->cosineSimilarity($next_emb, $candidate_emb);
                
                if ($sim_prev > $sim_next) {
                    $next_sentences = $this->splitTextIntoSentences($adjusted_chunks[$i+1]);
                    $candidate_text = implode(" ", array_slice($next_sentences, 0, $this->transfer_sentence_count));
                    $adjusted_chunks[$i] = trim($adjusted_chunks[$i]) . " " . $candidate_text;
                    $adjusted_chunks[$i+1] = implode(" ", array_slice($next_sentences, $this->transfer_sentence_count));
                }
            }
        }
        return $adjusted_chunks;
    }

    /**
     * Verilen metinleri, kural tabanlı segmentasyon, semantik birleştirme, sınır ayarlaması ve
     * uzun chunk'ların kelime sınırına göre bölünmesi adımlarından geçirerek dokümanlar oluşturur.
     * Her doküman, "page_content" anahtarına sahip bir dizi olarak döndürülür.
     */
    public function createDocuments($texts) {
        $all_chunks = [];
        foreach ($texts as $text) {
            if (mb_strlen($text) <= 10000) {
                $all_chunks[] = ["page_content" => $text];
            } else {
                $segments = $this->ruleBasedSegmentation($text);
                $initial_chunks = $this->semanticMerging($segments);
                $adjusted_chunks = $this->adjustBoundaries($initial_chunks);
                $final_chunks = [];
                foreach ($adjusted_chunks as $chunk) {
                    if (mb_strlen($chunk) > 10000) {
                        $sub_chunks = $this->splitSegmentByWordBoundary($chunk, 10000);
                        foreach ($sub_chunks as $sub) {
                            $final_chunks[] = ["page_content" => $sub];
                        }
                    } else {
                        $final_chunks[] = ["page_content" => $chunk];
                    }
                }
                $all_chunks = array_merge($all_chunks, $final_chunks);
            }
        }
        return $all_chunks;
    }
}

// Örnek kullanım:
// $semanticChunking = new SemanticChunking("openai-model-adı", 6, 2);
// $documents = $semanticChunking->createDocuments([$metin1, $metin2]);
?>
