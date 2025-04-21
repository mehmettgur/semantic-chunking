# Semantic Chunking

<img src="https://github.com/user-attachments/assets/e2212891-31b1-46ea-8f4e-f9d280f8a671" alt="ImageGen 20 Mar 2025 16_45_36" width="400">   ![image](https://github.com/user-attachments/assets/7a4e56a4-909e-4760-8dd5-6a153717eb0d)

 
**splitSegmentByWordBoundary**
Çok uzun segmentleri, kelime sınırlarını koruyarak belirli bir maksimum karakter uzunluğuna (örneğin, 10000 karakter) göre parçalara böler. Bölme işlemi sırasında kelimelerin bütünlüğü korunur; yani bir kelime ya tamamen bir parçada yer alır ya da bir sonraki parçaya aktarılır. Bu sayede büyük metinler, anlamsal bütünlük kaybı yaşamadan daha yönetilebilir parçalara ayrılır.

**preprocessSegments**
Segmentleri ön işlemeden geçirir; metnin başındaki ve sonundaki boşlukları temizler, çok kısa segmentleri (3 karakterden az) eler ve 10000 karakterden uzun segmentleri, splitSegmentByWordBoundary fonksiyonunu kullanarak daha küçük parçalara böler. Bu adım, aşırı kısa veya büyük segmentlerin algoritmayı olumsuz etkilemesini önler.

**splitTextIntoSentences**
Metni, nokta, ünlem gibi cümle sonu işaretleri ile yeni satır karakterlerine dayanarak cümlelere böler. İlk olarak, uygun regular expression ile cümle sınırları tespit edilir; ardından, elde edilen cümleler yaklaşık 750 karakter sınırına kadar birleştirilir. Böylece, çok kısa cümleler bir araya getirilip, aşırı uzun cümleler daha uygun uzunlukta gruplara dönüştürülür.

**ruleBasedSegmentation**
Metni önce splitTextIntoSentences ile cümlelere ayırır, ardından preprocessSegments işlemini uygulayarak anlamsal analiz için uygun ilk segmentlere bölünmesini sağlar.

**createEmbeddings**
Verilen metin parçalarını, belirlenen embedding modelini kullanarak vektörlere dönüştürür. Tüm metin parçaları tek seferde işlenir ve OpenAI API'si gibi bir servis üzerinden toplu (batch) olarak embedding oluşturulur. Bu yöntem, ayrı ayrı API çağrılarına kıyasla performans artışı sağlar.

**calculateDynamicThresholdFromDivergences**
Pencere içerisindeki embedding’ler arasındaki divergence (farklılık) değerlerini temel alarak dinamik bir eşik (threshold) hesaplar. İlk olarak divergence değerlerinin ortalaması ve standart sapması bulunur. Standart sapma:

0.1’den küçükse (parçalar birbirine çok benziyorsa) 1.4 faktörü,
0.1 ile 0.3 arasında ise 1.2 faktörü,
0.3’ten büyükse (parçalar arasında belirgin farklılık varsa) 1.0 faktörü
ile çarpılarak eşik değeri belirlenir. Bu yaklaşım, farklı metin yapılarına uyum sağlayarak daha doğru bölme noktaları tespit edilmesine olanak tanır.

**semanticMerging**
Segmentler üzerinde sliding window (kaydırmalı pencere) yöntemi kullanılarak, ardışık segmentler arasındaki cosine similarity hesaplanır. Her pencere için hesaplanan divergence değerleri, calculateDynamicThresholdFromDivergences fonksiyonu ile belirlenen yerel eşik değerini aşıyorsa, o nokta split point (bölme noktası) olarak işaretlenir. Tüm metin tarandıktan sonra, bu noktalara göre segmentler birleştirilerek nihai chunk’lar oluşturulur.

**adjustBoundaries**
Oluşturulan chunk’lar arasındaki sınırları optimize eder. İki chunk arasındaki geçişte, sonraki chunk’ın ilk transfer_sentence_count cümlesi, hem önceki chunk ile hem de kendi kalan kısmıyla olan benzerliği karşılaştırılır. Eğer bu cümleler, önceki chunk ile daha yüksek benzerlik gösteriyorsa, o cümleler önceki chunk’a aktarılır. Bu işlem, anlamsal uyumu artırır ve sınırların daha doğru belirlenmesini sağlar.

**createDocuments**
Verilen metinler üzerinde tüm işlem hattını (pipeline) çalıştıran ana fonksiyondur. Her metin için şu adımlar uygulanır:

Kısa metinler: Eğer metin 10000 karakter veya daha kısa ise, olduğu gibi bir doküman olarak döndürülür.
Uzun metinler:
Öncelikle, ruleBasedSegmentation ile metin cümlelere ve segmentlere ayrılır.
Ardından, semanticMerging ile anlamsal bazda birleştirme yapılır.
adjustBoundaries fonksiyonu ile oluşturulan chunk’lar arasındaki sınırlar optimize edilir.
Son olarak, herhangi bir chunk 10000 karakteri aşarsa, splitSegmentByWordBoundary ile daha küçük parçalara bölünür.
Her doküman, "page_content" anahtarına sahip bir dizi olarak döndürülür.
